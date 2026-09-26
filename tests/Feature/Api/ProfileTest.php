<?php

use App\Enums\AppLocale;
use App\Models\User;
use Laravel\Passport\Passport;

it('reports whether the user has a password and still needs to choose a name', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.has_password', true)
        ->assertJsonPath('data.needs_name', false);

    Passport::actingAs(User::factory()->withoutPassword()->create(['needs_name' => true]));

    $this->getJson('/api/v1/me')
        ->assertJsonPath('data.has_password', false)
        ->assertJsonPath('data.needs_name', true);
});

it('sets the name, which answers the prompt for one', function () {
    $user = User::factory()->withoutPassword()->create(['name' => 'Handlelista user', 'needs_name' => true]);
    Passport::actingAs($user);

    $this->patchJson('/api/v1/me/profile', ['name' => '  Kari Nordmann  '])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Kari Nordmann')
        ->assertJsonPath('data.needs_name', false);

    expect($user->fresh())->name->toBe('Kari Nordmann')->needs_name->toBeFalse();
});

it('rejects a missing or overlong name', function (mixed $name) {
    Passport::actingAs(User::factory()->create(['name' => 'Kari']));

    $this->patchJson('/api/v1/me/profile', ['name' => $name])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('name');
})->with(['', '   ', null, str_repeat('x', 256)]);

it('answers in the language the app asks for, or else the one saved on the account', function (string $acceptLanguage, AppLocale $saved, string $message) {
    Passport::actingAs(User::factory()->create(['locale' => $saved]));

    $this->withHeader('Accept-Language', $acceptLanguage)
        ->deleteJson('/api/v1/me')
        ->assertJsonValidationErrors(['password' => $message]);
})->with([
    'bokmål' => ['nb', AppLocale::English, 'Skriv inn passordet ditt for å slette kontoen.'],
    'nynorsk' => ['nn-NO', AppLocale::English, 'Skriv inn passordet ditt for å slette kontoen.'],
    'english over the saved language' => ['en', AppLocale::Norwegian, 'Enter your password to delete your account.'],
    'unsupported language' => ['de-DE', AppLocale::Norwegian, 'Enter your password to delete your account.'],
    'saved language without a header' => ['', AppLocale::Norwegian, 'Skriv inn passordet ditt for å slette kontoen.'],
]);
