<?php

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('deletes an account with a password once the password is confirmed', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

    $this->assertModelMissing($user);
});

it('refuses to delete an account with a password without the right password', function (array $proof, string $message) {
    $user = User::factory()->create(['email' => 'kari@example.com']);
    Passport::actingAs($user);

    $this->withHeader('Accept-Language', 'nb')
        ->deleteJson('/api/v1/me', $proof)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => $message]);

    $this->assertModelExists($user);
})->with([
    'wrong password' => [['password' => 'not-it'], 'Passordet er feil.'],
    'no password' => [[], 'Skriv inn passordet ditt for å slette kontoen.'],
    // Typing the email is only for accounts that have nothing else to check.
    'email instead' => [['email' => 'kari@example.com'], 'Skriv inn passordet ditt for å slette kontoen.'],
]);

it('deletes a passwordless account when its email is typed to confirm', function () {
    $account = SocialAccount::factory()->create();
    $account->user->forceFill(['email' => 'kari@example.com', 'password' => null])->save();
    Passport::actingAs($account->user);

    $this->deleteJson('/api/v1/me', ['email' => ' Kari@Example.com '])->assertNoContent();

    $this->assertModelMissing($account->user);
    $this->assertModelMissing($account);
});

it('refuses to delete a passwordless account without its email', function (array $proof, string $message) {
    $user = User::factory()->withoutPassword()->create(['email' => 'kari@example.com']);
    Passport::actingAs($user);

    $this->deleteJson('/api/v1/me', $proof)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['email' => $message]);

    $this->assertModelExists($user);
})->with([
    'wrong email' => [['email' => 'someone@example.com'], 'This is not the email address of your account.'],
    'nothing' => [[], 'Type your email address to confirm that you want to delete your account.'],
]);
