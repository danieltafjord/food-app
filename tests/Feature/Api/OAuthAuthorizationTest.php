<?php

use App\Models\User;
use Laravel\Passport\ClientRepository;

/**
 * Build a PKCE code challenge from a verifier (S256).
 */
function pkceChallenge(string $verifier): string
{
    return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
}

it('skips consent for a first-party client and redirects with an authorization code', function () {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Food App Mobile',
        redirectUris: ['foodapp://oauth/callback'],
        confidential: false,
        user: null,
    );

    $response = $this->actingAs(User::factory()->create())
        ->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->getKey(),
            'redirect_uri' => 'foodapp://oauth/callback',
            'code_challenge' => pkceChallenge(str_repeat('a', 64)),
            'code_challenge_method' => 'S256',
            'state' => 'test-state',
        ]));

    $response->assertRedirect();

    expect($response->headers->get('Location'))
        ->toStartWith('foodapp://oauth/callback?')
        ->toContain('code=')
        ->toContain('state=test-state');
});

it('renders the consent screen for a third-party client', function () {
    $owner = User::factory()->create();

    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Third Party App',
        redirectUris: ['https://third-party.test/callback'],
        confidential: true,
        user: $owner,
    );

    $this->actingAs(User::factory()->create())
        ->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://third-party.test/callback',
            'state' => 'test-state',
        ]))
        ->assertOk()
        ->assertSee('Third Party App')
        ->assertSee('Authorization request');
});
