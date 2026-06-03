<?php

use App\Enums\AppLocale;
use App\Enums\Theme;
use App\Models\User;
use Laravel\Passport\Passport;

it('returns the default settings for a new user', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.theme', 'system')
        ->assertJsonPath('data.locale', 'en');
});

it('returns the persisted settings of the user', function () {
    Passport::actingAs(User::factory()->create([
        'theme' => Theme::Dark,
        'locale' => AppLocale::Norwegian,
    ]));

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.theme', 'dark')
        ->assertJsonPath('data.locale', 'nb');
});

it('updates the theme and language and returns the updated user', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->patchJson('/api/v1/me/settings', [
        'theme' => 'dark',
        'locale' => 'nb',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.theme', 'dark')
        ->assertJsonPath('data.locale', 'nb');

    expect($user->refresh())
        ->theme->toBe(Theme::Dark)
        ->locale->toBe(AppLocale::Norwegian);
});

it('rejects an unknown theme', function () {
    Passport::actingAs(User::factory()->create());

    $this->patchJson('/api/v1/me/settings', [
        'theme' => 'midnight',
        'locale' => 'en',
    ])->assertJsonValidationErrors('theme');
});

it('rejects an unsupported locale', function () {
    Passport::actingAs(User::factory()->create());

    $this->patchJson('/api/v1/me/settings', [
        'theme' => 'light',
        'locale' => 'de',
    ])->assertJsonValidationErrors('locale');
});

it('rejects unauthenticated settings updates', function () {
    $this->patchJson('/api/v1/me/settings', [
        'theme' => 'dark',
        'locale' => 'nb',
    ])->assertUnauthorized();
});
