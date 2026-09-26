<?php

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

function googleIdentity(string $id = '1234567890', string $email = 'kari@example.com', bool $emailVerified = true): GoogleUser
{
    return (new GoogleUser)
        ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $emailVerified])
        ->map(['id' => $id, 'name' => 'Kari Nordmann', 'email' => $email]);
}

function enableGoogleSignIn(): void
{
    config(['services.google.client_id' => 'google-client-id', 'services.google.client_secret' => 'google-secret']);
}

it('creates a passwordless account for a new Google user and signs them in', function () {
    enableGoogleSignIn();
    Socialite::fake('google', googleIdentity());

    $this->get(route('social.callback', 'google'))->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'kari@example.com')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Kari Nordmann')
        ->and($user->hasPassword())->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->socialAccounts()->sole()->provider)->toBe(SocialProvider::Google);
});

it('returns an app sign-in to the OAuth authorize endpoint', function () {
    enableGoogleSignIn();
    $account = SocialAccount::factory()->create(['provider_user_id' => '1234567890']);
    Socialite::fake('google', googleIdentity());
    $authorizeUrl = url('/oauth/authorize').'?client_id=app&response_type=code';

    $this->withSession(['url.intended' => $authorizeUrl])
        ->get(route('social.callback', 'google'))
        ->assertRedirect($authorizeUrl);

    $this->assertAuthenticatedAs($account->user);
});

it('sends a two-factor account through the challenge before finishing the app sign-in', function () {
    enableGoogleSignIn();
    $user = User::factory()->withTwoFactor()->create();
    SocialAccount::factory()->for($user)->create(['provider_user_id' => '1234567890']);
    Socialite::fake('google', googleIdentity());
    $authorizeUrl = url('/oauth/authorize').'?client_id=app&response_type=code';

    $this->withSession(['url.intended' => $authorizeUrl])
        ->get(route('social.callback', 'google'))
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id);
    $this->assertGuest();

    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertRedirect($authorizeUrl);
    $this->assertAuthenticatedAs($user);
});

it('does not create an account from an unverified Google email', function () {
    enableGoogleSignIn();
    Socialite::fake('google', googleIdentity(emailVerified: false));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['social' => 'Google did not share a verified email address.']);

    $this->assertGuest();
    expect(User::query()->exists())->toBeFalse();
});

it('returns to the login page when the person cancels at Google', function () {
    enableGoogleSignIn();

    $this->get(route('social.callback', ['provider' => 'google', 'error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasNoErrors();

    $this->assertGuest();
});

it('is unavailable until Google credentials are configured', function (string $provider) {
    config(['services.google.client_id' => null]);

    $this->get(route('social.redirect', $provider))->assertNotFound();
    $this->get(route('social.callback', $provider))->assertNotFound();
})->with(['google', 'apple']);

it('goes straight to Google when the app asks for it, dropping the hint', function () {
    enableGoogleSignIn();
    $authorizeUrl = url('/oauth/authorize').'?client_id=app&response_type=code';

    $this->withSession(['url.intended' => $authorizeUrl.'&provider=google'])
        ->get(route('login'))
        ->assertRedirect(route('social.redirect', 'google'))
        ->assertSessionHas('url.intended', $authorizeUrl);
});

it('shows the login form with the Google option otherwise', function () {
    enableGoogleSignIn();

    $this->get(route('login'))->assertInertia(fn ($page) => $page
        ->component('auth/Login')
        ->where('socialProviders', ['google']));
});

it('confirms a passwordless user with their linked Google account', function () {
    enableGoogleSignIn();
    $user = User::factory()->withoutPassword()->create();
    SocialAccount::factory()->for($user)->create(['provider_user_id' => '1234567890']);
    Socialite::fake('google', googleIdentity());

    $this->actingAs($user)
        ->withSession(['url.intended' => route('security.edit')])
        ->get(route('social.redirect', ['provider' => 'google', 'confirm' => 1]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('auth.password_confirmed_at');
});

it('does not confirm with a Google account that belongs to someone else', function () {
    enableGoogleSignIn();
    $user = User::factory()->withoutPassword()->create();
    SocialAccount::factory()->for($user)->create(['provider_user_id' => 'mine']);
    Socialite::fake('google', googleIdentity(id: 'someone-else', email: $user->email));

    $this->actingAs($user)->get(route('social.redirect', ['provider' => 'google', 'confirm' => 1]));

    $this->get(route('social.callback', 'google'))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors('social')
        ->assertSessionMissing('auth.password_confirmed_at');

    expect($user->socialAccounts()->count())->toBe(1);
});
