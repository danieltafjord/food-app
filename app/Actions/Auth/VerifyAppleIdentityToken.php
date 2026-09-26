<?php

namespace App\Actions\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

class VerifyAppleIdentityToken
{
    public const string ISSUER = 'https://appleid.apple.com';

    public const string KEYS_URL = 'https://appleid.apple.com/auth/keys';

    private const string KEYS_CACHE_KEY = 'apple-sign-in:keys';

    private const string REFETCH_COOLDOWN_KEY = 'apple-sign-in:keys-refetched';

    /** Seconds of clock skew allowed between Apple and this server. */
    private const int LEEWAY_SECONDS = 60;

    /**
     * Check an identity token from Sign in with Apple and return who it is for.
     *
     * The token must be signed by one of Apple's current keys, issued for this
     * app, unexpired, and carry the SHA-256 of the nonce the app generated for
     * this sign-in, so a token lifted from another sign-in cannot be replayed.
     *
     * @return array{sub: string, email: ?string, email_verified: bool}
     *
     * @throws ValidationException
     */
    public function handle(string $identityToken, string $nonce): array
    {
        try {
            $claims = $this->decode($identityToken);
        } catch (Throwable) {
            throw $this->invalid();
        }

        $audiences = (array) ($claims->aud ?? []);

        if (($claims->iss ?? null) !== self::ISSUER
            || ! in_array(config('services.apple.client_id'), $audiences, true)
            || ! is_string($claims->sub ?? null) || $claims->sub === ''
            || ! is_string($claims->nonce ?? null)
            || ! hash_equals($claims->nonce, hash('sha256', $nonce))) {
            throw $this->invalid();
        }

        $email = is_string($claims->email ?? null) ? $claims->email : null;

        return [
            'sub' => $claims->sub,
            'email' => $email,
            // Apple sends this as a boolean or as the string "true".
            'email_verified' => $email !== null && filter_var($claims->email_verified ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * firebase/php-jwt reads its leeway from a static property; it is set only
     * for this decode so other JWT checks keep their own.
     */
    private function decode(string $identityToken): object
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            return JWT::decode($identityToken, JWK::parseKeySet($this->keysFor($identityToken), 'RS256'));
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /**
     * Apple's signing keys, cached for a day. A token signed with a key the
     * cache has not seen yet (Apple rotated them) fetches the set again, at
     * most once a minute, so made-up key ids cannot hammer Apple through us.
     *
     * @return array{keys: list<array<string, string>>}
     */
    private function keysFor(string $identityToken): array
    {
        $keys = Cache::remember(self::KEYS_CACHE_KEY, now()->addDay(), fn (): array => $this->fetchKeys());
        $keyId = $this->keyId($identityToken);

        if ($keyId !== null && ! collect($keys['keys'])->contains('kid', $keyId)
            && Cache::add(self::REFETCH_COOLDOWN_KEY, true, now()->addMinute())) {
            $keys = $this->fetchKeys();
            Cache::put(self::KEYS_CACHE_KEY, $keys, now()->addDay());
        }

        return $keys;
    }

    /**
     * @return array{keys: list<array<string, string>>}
     */
    private function fetchKeys(): array
    {
        return Http::timeout(10)->retry(2, 200, throw: false)->get(self::KEYS_URL)->throw()->json();
    }

    private function keyId(string $identityToken): ?string
    {
        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode(explode('.', $identityToken)[0]));
        } catch (Throwable) {
            return null;
        }

        return is_string($header->kid ?? null) ? $header->kid : null;
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'identity_token' => __('account.apple_unverified'),
        ]);
    }
}
