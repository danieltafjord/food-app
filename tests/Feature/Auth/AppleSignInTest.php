<?php

use App\Actions\Auth\VerifyAppleIdentityToken;
use App\Auth\Grants\AppleSignInGrant;
use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

function appleSignInClient(bool $trusted = true): Client
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Handlelista', redirectUris: ['foodapp://oauth/callback'], confidential: false,
    );
    $client->forceFill(['trusted' => $trusted])->save();

    return $client;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function appleTokenRequest(Client $client, array $overrides = []): array
{
    return [
        'grant_type' => AppleSignInGrant::IDENTIFIER,
        'client_id' => $client->getKey(),
        'identity_token' => appleIdentityToken(),
        'nonce' => 'raw-nonce',
        ...$overrides,
    ];
}

it('creates a passwordless account for a new Apple user and signs them in', function () {
    Http::preventStrayRequests();
    $client = appleSignInClient();

    $credentials = $this->postJson('/oauth/token', appleTokenRequest($client, [
        'given_name' => 'Kari',
        'family_name' => 'Nordmann',
    ]))->assertOk()->json();

    $user = User::query()->where('email', 'apple-user@example.com')->sole();
    expect($user->name)->toBe('Kari Nordmann')
        ->and($user->needs_name)->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->hasPassword())->toBeFalse()
        ->and($credentials['refresh_token'])->toBeString();
    $this->assertDatabaseHas('social_accounts', [
        'user_id' => $user->id,
        'provider' => SocialProvider::Apple->value,
        'provider_user_id' => '001234.apple-user.1234',
    ]);

    // A full mobile-API token, like the browser sign-in yields.
    $this->withToken($credentials['access_token'])->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.sign_in_providers', ['apple']);
});

it('signs a returning Apple user into their linked account', function () {
    Http::preventStrayRequests();
    $account = SocialAccount::factory()->apple()->create();

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), [
        'identity_token' => appleIdentityToken(['sub' => $account->provider_user_id, 'email' => 'relay@privaterelay.appleid.com']),
    ]))->assertOk();

    expect($account->user->tokens()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1);
});

it('links Apple to an existing account with the same verified email', function () {
    Http::preventStrayRequests();
    $user = User::factory()->create(['email' => 'Apple-User@example.com']);

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient()))->assertOk();

    expect($user->socialAccounts()->sole()->provider)->toBe(SocialProvider::Apple)
        ->and($user->tokens()->count())->toBe(1)
        ->and($user->fresh()->hasPassword())->toBeTrue();
});

it('does not join an account whose email was never verified', function () {
    Http::preventStrayRequests();
    $user = User::factory()->unverified()->create(['email' => 'apple-user@example.com']);

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient()))
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant')
        ->assertJsonPath('hint', 'An account with this email already exists. Log in with your password and verify your email first; after that you can use Apple.');

    expect($user->socialAccounts()->exists())->toBeFalse()
        ->and($user->tokens()->exists())->toBeFalse();
});

it('refuses a deactivated account', function () {
    Http::preventStrayRequests();
    $account = SocialAccount::factory()->apple()->for(User::factory()->deactivated())->create();

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), [
        'identity_token' => appleIdentityToken(['sub' => $account->provider_user_id]),
    ]))->assertStatus(400)->assertJsonPath('hint', 'This account has been deactivated.');

    expect($account->user->tokens()->exists())->toBeFalse();
});

it('rejects an identity token that is not valid for this sign-in', function (array $claims, string $nonce) {
    Http::preventStrayRequests();

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), [
        'identity_token' => appleIdentityToken($claims),
        'nonce' => $nonce,
    ]))->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(User::query()->exists())->toBeFalse();
})->with([
    'another sign-in\'s nonce' => [[], 'other-nonce'],
    'another app' => [['aud' => 'com.example.other'], 'raw-nonce'],
    'another issuer' => [['iss' => 'https://accounts.google.com'], 'raw-nonce'],
    'expired' => [['exp' => time() - 3600, 'iat' => time() - 7200], 'raw-nonce'],
]);

it('gives a Hide My Email sign-up without a name a neutral one and asks for a real one', function (string $acceptLanguage, string $name) {
    Http::preventStrayRequests();

    $credentials = $this->withHeader('Accept-Language', $acceptLanguage)->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), [
        'identity_token' => appleIdentityToken(['email' => 'x7k2p9qd4m@privaterelay.appleid.com']),
    ]))->assertOk()->json();

    expect(User::query()->sole())->name->toBe($name)->needs_name->toBeTrue();
    $this->withToken($credentials['access_token'])->getJson('/api/v1/me')->assertJsonPath('data.needs_name', true);
})->with([
    'english' => ['en', 'Handlelista user'],
    'norwegian' => ['nb', 'Handlelista-bruker'],
]);

it('names a sign-up without a name after their email address until they choose one', function () {
    Http::preventStrayRequests();

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient()))->assertOk();

    expect(User::query()->sole())->name->toBe('apple-user')->needs_name->toBeTrue();
});

it('explains a failed sign-in in the app\'s language', function () {
    Http::preventStrayRequests();

    $this->withHeader('Accept-Language', 'nb')->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), ['nonce' => 'other-nonce']))
        ->assertStatus(400)
        ->assertJsonPath('hint', 'Vi kunne ikke bekrefte innloggingen med Apple. Prøv igjen.');
});

it('allows a minute of clock difference with Apple', function (array $claims) {
    Http::preventStrayRequests();

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), [
        'identity_token' => appleIdentityToken($claims),
    ]))->assertOk();
})->with([
    'issued slightly in the future' => [['iat' => time() + 30]],
    'expired moments ago' => [['iat' => time() - 600, 'exp' => time() - 30]],
]);

it('refetches Apple\'s keys for an unknown key id at most once a minute', function () {
    Http::preventStrayRequests();
    appleIdentityToken();
    $unknownKey = JWT::urlsafeB64Encode(json_encode(['alg' => 'RS256', 'kid' => 'made-up'])).'.e30.c2ln';
    $attempt = fn () => rescue(fn () => app(VerifyAppleIdentityToken::class)->handle($unknownKey, 'raw-nonce'), report: false);

    $attempt();
    $attempt();
    $attempt();
    Http::assertSentCount(2);

    $this->travel(61)->seconds();
    $attempt();
    Http::assertSentCount(3);
});

it('rejects a token that Apple did not sign', function () {
    Http::preventStrayRequests();
    appleIdentityToken();
    $foreignKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($foreignKey, $foreignPem);
    $forged = JWT::encode([
        'iss' => 'https://appleid.apple.com', 'aud' => 'no.handlelistaapp', 'exp' => time() + 600,
        'sub' => 'forged', 'email' => 'forged@example.com', 'email_verified' => true, 'nonce' => hash('sha256', 'raw-nonce'),
    ], $foreignPem, 'RS256', 'test-key');

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), ['identity_token' => $forged]))
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    expect(User::query()->exists())->toBeFalse();
});

it('only lets trusted first-party clients use the Apple grant', function () {
    Http::preventStrayRequests();

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient(trusted: false)))
        ->assertStatus(400)->assertJsonPath('error', 'unauthorized_client');

    expect(User::query()->exists())->toBeFalse();
});

it('keeps an Apple refresh token so the account can be revoked at Apple later', function () {
    Http::preventStrayRequests();
    configureAppleKey();
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response(['refresh_token' => 'apple-refresh-token'])]);

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), ['authorization_code' => 'apple-code']))
        ->assertOk();

    expect(SocialAccount::query()->sole()->refresh_token)->toBe('apple-refresh-token');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://appleid.apple.com/auth/token'
        && $request['code'] === 'apple-code'
        && $request['client_id'] === 'no.handlelistaapp');
});

it('still signs in when exchanging the Apple code fails', function () {
    Http::preventStrayRequests();
    configureAppleKey();
    Http::fake(['https://appleid.apple.com/auth/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    $this->postJson('/oauth/token', appleTokenRequest(appleSignInClient(), ['authorization_code' => 'expired-code']))
        ->assertOk();

    expect(SocialAccount::query()->sole()->refresh_token)->toBeNull();
});
