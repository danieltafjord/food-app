<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('privacy and support are available without an account', function (string $route, string $component) {
    $this->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component($component)
            ->where('auth.user', null)
            ->where('contact.operatorName', 'Daniel Tafjord')
            ->where('contact.supportEmail', 'support@handlelistaapp.no')
        );
})->with([
    'privacy' => ['privacy', 'Privacy'],
    'support' => ['support', 'Support'],
]);

test('unverified members can still access privacy and support', function (string $route, string $component) {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route($route))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'privacy' => ['privacy', 'Privacy'],
    'support' => ['support', 'Support'],
]);
