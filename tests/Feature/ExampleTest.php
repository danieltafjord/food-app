<?php

use Inertia\Testing\AssertableInertia as Assert;

test('the landing page is available without an account', function () {
    $this->get(route('localized.home', ['locale' => 'en']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('auth.user', null)
        );
});
