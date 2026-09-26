<?php

namespace App\Auth\Grants;

use App\Actions\Auth\AppleTokens;
use App\Actions\Auth\ResolveSocialUser;
use App\Actions\Auth\VerifyAppleIdentityToken;
use App\Enums\SocialProvider;
use App\Models\User;
use DateInterval;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AbstractGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestAccessTokenEvent;
use League\OAuth2\Server\RequestEvent;
use League\OAuth2\Server\RequestRefreshTokenEvent;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Native Sign in with Apple for the iOS app: exchanges an Apple identity token
 * at `/oauth/token` for the same access + refresh token pair the browser
 * sign-in yields, so the app keeps one session model.
 *
 * Request: grant_type, client_id, identity_token, nonce (the raw value whose
 * SHA-256 the app gave Apple), and optionally authorization_code, given_name
 * and family_name (Apple shares the name only on the very first sign-in).
 */
class AppleSignInGrant extends AbstractGrant
{
    public const string IDENTIFIER = 'urn:handlelista:params:oauth:grant-type:apple';

    public function __construct(
        private VerifyAppleIdentityToken $verifyIdentityToken,
        private ResolveSocialUser $resolveUser,
        private AppleTokens $appleTokens,
        RefreshTokenRepositoryInterface $refreshTokenRepository,
    ) {
        $this->setRefreshTokenRepository($refreshTokenRepository);
        $this->refreshTokenTTL = new DateInterval('P1M');
    }

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function respondToAccessTokenRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        DateInterval $accessTokenTTL,
    ): ResponseTypeInterface {
        $client = $this->validateClient($request);
        $scopes = $this->validateScopes($this->getRequestParameter('scope', $request, $this->defaultScope));
        $userId = (string) $this->validateUser($request)->getAuthIdentifier();

        $finalizedScopes = $this->scopeRepository->finalizeScopes($scopes, $this->getIdentifier(), $client, $userId);

        $accessToken = $this->issueAccessToken($accessTokenTTL, $client, $userId, $finalizedScopes);
        $this->getEmitter()->emit(new RequestAccessTokenEvent(RequestEvent::ACCESS_TOKEN_ISSUED, $request, $accessToken));
        $responseType->setAccessToken($accessToken);

        $refreshToken = $this->issueRefreshToken($accessToken);

        if ($refreshToken !== null) {
            $this->getEmitter()->emit(new RequestRefreshTokenEvent(RequestEvent::REFRESH_TOKEN_ISSUED, $request, $refreshToken));
            $responseType->setRefreshToken($refreshToken);
        }

        return $responseType;
    }

    /**
     * Only trusted first-party clients (the mobile app) may sign people in
     * this way; the tokens they get reach the whole mobile API.
     */
    protected function supportsGrantType(ClientEntityInterface $client, string $grantType): bool
    {
        if ($grantType !== self::IDENTIFIER) {
            return parent::supportsGrantType($client, $grantType);
        }

        return (bool) Passport::client()->newQuery()->whereKey($client->getIdentifier())->value('trusted');
    }

    /**
     * @throws OAuthServerException
     */
    private function validateUser(ServerRequestInterface $request): User
    {
        $identityToken = $this->getRequestParameter('identity_token', $request)
            ?? throw OAuthServerException::invalidRequest('identity_token');
        $nonce = $this->getRequestParameter('nonce', $request)
            ?? throw OAuthServerException::invalidRequest('nonce');

        try {
            $identity = $this->verifyIdentityToken->handle($identityToken, $nonce);
            $user = $this->resolveUser->handle(
                SocialProvider::Apple,
                $identity['sub'],
                $identity['email'],
                $identity['email_verified'],
                $this->fullName($request),
            );
        } catch (ValidationException $exception) {
            $this->getEmitter()->emit(new RequestEvent(RequestEvent::USER_AUTHENTICATION_FAILED, $request));

            throw OAuthServerException::invalidGrant(collect($exception->errors())->flatten()->first());
        }

        $this->rememberAppleRefreshToken($request, $user, $identity['sub']);

        return $user;
    }

    private function fullName(ServerRequestInterface $request): ?string
    {
        $name = trim(implode(' ', array_filter([
            $this->getRequestParameter('given_name', $request),
            $this->getRequestParameter('family_name', $request),
        ], 'is_string')));

        return $name !== '' ? $name : null;
    }

    /**
     * Keep a refresh token so deleting the account can revoke the app's access
     * at Apple. A failed exchange never blocks the sign-in.
     */
    private function rememberAppleRefreshToken(ServerRequestInterface $request, User $user, string $appleUserId): void
    {
        $authorizationCode = $this->getRequestParameter('authorization_code', $request);

        if (! is_string($authorizationCode) || $authorizationCode === '') {
            return;
        }

        $refreshToken = $this->appleTokens->exchange($authorizationCode);

        if ($refreshToken !== null) {
            $user->socialAccounts()
                ->where('provider', SocialProvider::Apple)
                ->where('provider_user_id', $appleUserId)
                ->first()
                ?->update(['refresh_token' => $refreshToken]);
        }
    }
}
