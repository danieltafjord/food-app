<?php

use App\Models\User;

test('an oauth login is delivered as a full-page inertia visit back to the authorize endpoint', function () {
    $user = User::factory()->create();

    $authorizeUrl = url('/oauth/authorize').'?client_id=test&response_type=code&redirect_uri='
        .urlencode('foodapp://oauth/callback');

    $response = $this->withSession(['url.intended' => $authorizeUrl])
        ->withHeader('X-Inertia', 'true')
        ->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $this->assertAuthenticatedAs($user);

    // Inertia::location() => a 409 the client turns into a real window.location
    // navigation, so the browser actually reaches the authorize endpoint (which
    // then 302s out to foodapp://oauth/callback).
    $response->assertStatus(409);
    expect($response->headers->get('X-Inertia-Location'))->toContain('/oauth/authorize');
});

test('a normal web login still redirects to the dashboard', function () {
    $user = User::factory()->create();

    $response = $this->withHeader('X-Inertia', 'true')
        ->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect('/dashboard');
});
