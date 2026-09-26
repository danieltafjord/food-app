<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('lets someone who signed up with Google set a first password', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('still requires the current password once one is set', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertSessionHasErrors('current_password');
});

it('asks a passwordless user to confirm before deleting their account', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)->delete(route('profile.destroy'))
        ->assertRedirect(route('password.confirm'));

    $this->assertModelExists($user);
});

it('deletes a passwordless account after a recent confirmation', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('profile.destroy'))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertModelMissing($user);
});

it('cannot log in with an empty password to a passwordless account', function () {
    $user = User::factory()->withoutPassword()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => ''])
        ->assertSessionHasErrors('password');
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
