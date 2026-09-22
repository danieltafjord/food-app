<?php

use App\Models\User;

test('guests are redirected to the login page from admin pages', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['admin.analytics', 'admin.ai.edit', 'admin.users.index']);

test('regular users cannot open admin pages', function (string $route) {
    $this->actingAs(User::factory()->create())
        ->get(route($route))
        ->assertForbidden();
})->with(['admin.analytics', 'admin.ai.edit', 'admin.users.index']);

test('regular users cannot change AI settings', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('admin.ai.update'), ['feature' => 'suggestions', 'model' => 'openai/gpt-5-mini'])
        ->assertForbidden();

    $this->assertDatabaseCount('app_settings', 0);
});

test('admins can open admin pages', function (string $route) {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route($route))
        ->assertOk();
})->with(['admin.analytics', 'admin.ai.edit', 'admin.users.index']);

test('the admin flag is shared with the front end', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('auth.user.is_admin', true));
});

test('the user:admin command grants and revokes access', function () {
    $user = User::factory()->create();

    $this->artisan('user:admin', ['email' => $user->email])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeTrue();

    $this->artisan('user:admin', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeFalse();

    $this->artisan('user:admin', ['email' => 'nobody@example.com'])->assertFailed();
});
