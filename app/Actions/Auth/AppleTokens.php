<?php

namespace App\Actions\Auth;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to Apple's token endpoints on the app's behalf, so that deleting an
 * account can revoke the app's Sign in with Apple access (App Store Review
 * Guideline 5.1.1(v)). Needs a Sign in with Apple key; without one it does
 * nothing and sign-in still works.
 */
class AppleTokens
{
    private const string TOKEN_URL = 'https://appleid.apple.com/auth/token';

    private const string REVOKE_URL = 'https://appleid.apple.com/auth/revoke';

    public function isConfigured(): bool
    {
        return filled(config('services.apple.team_id'))
            && filled(config('services.apple.key_id'))
            && filled(config('services.apple.private_key'));
    }

    /**
     * Trade the one-time authorization code from a sign-in for a refresh token.
     */
    public function exchange(string $authorizationCode): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'client_id' => config('services.apple.client_id'),
                'client_secret' => $this->clientSecret(),
                'code' => $authorizationCode,
                'grant_type' => 'authorization_code',
            ]);
        } catch (Throwable $exception) {
            Log::warning('Sign in with Apple code exchange failed.', ['exception' => $exception->getMessage()]);

            return null;
        }

        if ($response->failed() || ! is_string($response->json('refresh_token'))) {
            Log::warning('Sign in with Apple code exchange failed.', ['status' => $response->status(), 'error' => $response->json('error')]);

            return null;
        }

        return $response->json('refresh_token');
    }

    /**
     * Revoke a refresh token, which ends the app's access to the Apple ID.
     */
    public function revoke(string $refreshToken): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->retry(2, 500, throw: false)->post(self::REVOKE_URL, [
                'client_id' => config('services.apple.client_id'),
                'client_secret' => $this->clientSecret(),
                'token' => $refreshToken,
                'token_type_hint' => 'refresh_token',
            ]);
        } catch (Throwable $exception) {
            Log::warning('Sign in with Apple token revocation failed.', ['exception' => $exception->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Sign in with Apple token revocation failed.', ['status' => $response->status(), 'error' => $response->json('error')]);
        }

        return $response->successful();
    }

    /**
     * The short-lived ES256 JWT Apple accepts in place of a client secret.
     */
    private function clientSecret(): string
    {
        return JWT::encode([
            'iss' => config('services.apple.team_id'),
            'iat' => now()->getTimestamp(),
            'exp' => now()->addMinutes(5)->getTimestamp(),
            'aud' => VerifyAppleIdentityToken::ISSUER,
            'sub' => config('services.apple.client_id'),
        ], str_replace('\n', "\n", (string) config('services.apple.private_key')), 'ES256', config('services.apple.key_id'));
    }
}
