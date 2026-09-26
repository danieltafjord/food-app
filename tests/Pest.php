<?php

use App\Actions\Auth\VerifyAppleIdentityToken;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create a user who owns a fresh household, with that household active.
 *
 * @return array{0: User, 1: Household}
 */
function ownerWithHousehold(): array
{
    $owner = User::factory()->create();
    $household = Household::factory()->create();
    $household->members()->attach($owner, ['role' => HouseholdRole::Owner->value]);
    $owner->update(['current_household_id' => $household->id]);

    return [$owner, $household];
}

/**
 * Sign an Apple identity token the way Apple would, and serve the matching
 * public key from a faked Apple key endpoint. `$nonce` is the raw value the
 * app keeps; the token carries its SHA-256.
 *
 * @param  array<string, mixed>  $claims
 */
function appleIdentityToken(array $claims = [], string $nonce = 'raw-nonce'): string
{
    static $key = null;
    $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    openssl_pkey_export($key, $privateKey);

    Http::fake([VerifyAppleIdentityToken::KEYS_URL => Http::response(['keys' => [[
        'kty' => 'RSA', 'kid' => 'test-key', 'use' => 'sig', 'alg' => 'RS256',
        'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e']),
    ]]])]);

    return JWT::encode([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'no.handlelistaapp',
        'iat' => time(),
        'exp' => time() + 600,
        'sub' => '001234.apple-user.1234',
        'email' => 'apple-user@example.com',
        'email_verified' => 'true',
        'nonce' => hash('sha256', $nonce),
        ...$claims,
    ], $privateKey, 'RS256', 'test-key');
}

/**
 * Configure a Sign in with Apple key so the server can exchange and revoke tokens.
 */
function configureAppleKey(): void
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $privateKey);

    config([
        'services.apple.team_id' => 'TEAM123456',
        'services.apple.key_id' => 'KEY1234567',
        'services.apple.private_key' => $privateKey,
    ]);
}
