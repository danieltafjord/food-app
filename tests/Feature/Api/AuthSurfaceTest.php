<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

it('rejects unauthenticated access', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('returns the authenticated user with their current household', function () {
    $household = Household::factory()->create();
    $user = User::factory()->create(['current_household_id' => $household->id]);

    Passport::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.current_household.id', $household->id)
        ->assertJsonPath('data.current_household.name', $household->name);
});

it('returns a null current household when none is selected', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.current_household', null);
});

describe('token management', function () {
    beforeEach(function () {
        Artisan::call('passport:client', [
            '--personal' => true,
            '--name' => 'Test Personal Access Client',
            '--no-interaction' => true,
        ]);
    });

    it('lists the active devices for the user', function () {
        $user = User::factory()->create();
        $phone = $user->createToken('Phone')->accessToken;
        $user->createToken('Tablet');

        $this->withToken($phone)->getJson('/api/v1/auth/devices')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['name' => 'Phone', 'current' => true])
            ->assertJsonFragment(['name' => 'Tablet', 'current' => false]);
    });

    it('revokes the current token on logout', function () {
        $user = User::factory()->create();
        $created = $user->createToken('Phone');

        $this->withToken($created->accessToken)->postJson('/api/v1/auth/logout')->assertSuccessful();

        expect($user->tokens()->whereKey($created->token->id)->first()->revoked)->toBeTrue();
    });

    it('revokes a specific device without affecting others', function () {
        $user = User::factory()->create();
        $keepToken = $user->createToken('Keep');
        $keep = $keepToken->accessToken;
        $remove = $user->createToken('Remove');
        $keepRefresh = $keepToken->token->refreshToken()->create(['id' => Str::random(80), 'revoked' => false]);
        $removeRefresh = $remove->token->refreshToken()->create(['id' => Str::random(80), 'revoked' => false]);

        $this->withToken($keep)
            ->deleteJson("/api/v1/auth/devices/{$remove->token->id}")
            ->assertSuccessful();

        expect($user->tokens()->whereKey($remove->token->id)->first()->revoked)->toBeTrue();
        expect($removeRefresh->fresh()->revoked)->toBeTrue()
            ->and($keepRefresh->fresh()->revoked)->toBeFalse();
        $this->withToken($keep)->getJson('/api/v1/me')->assertSuccessful();
    });
});

it('refuses refreshing an OAuth session after logout or device revocation', function (string $operation) {
    $user = User::factory()->create();
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Mobile', redirectUris: ['foodapp://oauth/callback'], confidential: false,
    );
    $client->forceFill(['trusted' => true])->save();
    $verifier = str_repeat('a', 64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $authorization = $this->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $client->id,
        'redirect_uri' => 'foodapp://oauth/callback', 'code_challenge' => $challenge,
        'code_challenge_method' => 'S256', 'state' => 'logout-test',
    ]))->assertRedirect();
    parse_str(parse_url($authorization->headers->get('Location'), PHP_URL_QUERY), $query);
    $credentials = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $client->id,
        'redirect_uri' => 'foodapp://oauth/callback', 'code' => $query['code'],
        'code_verifier' => $verifier,
    ])->assertSuccessful()->json();
    $token = $user->tokens()->firstOrFail();

    if ($operation === 'logout') {
        $this->withToken($credentials['access_token'])->postJson('/api/v1/auth/logout')->assertSuccessful();
    } else {
        $this->withToken($credentials['access_token'])->deleteJson("/api/v1/auth/devices/{$token->id}")->assertSuccessful();
    }

    $this->postJson('/oauth/token', [
        'grant_type' => 'refresh_token', 'client_id' => $client->id,
        'refresh_token' => $credentials['refresh_token'],
    ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
})->with(['logout', 'device']);
