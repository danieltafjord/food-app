<?php

use Inertia\Testing\AssertableInertia as Assert;

test('unprefixed public pages redirect to the browser preferred language', function (string $acceptLanguage, string $expectedLocale) {
    $this->withHeader('Accept-Language', $acceptLanguage)
        ->get(route('home'))
        ->assertRedirect(route('localized.home', ['locale' => $expectedLocale]));
})->with([
    'norwegian bokmål' => ['nb-NO,nb;q=0.9,en;q=0.8', 'no'],
    'generic norwegian' => ['no', 'no'],
    'nynorsk' => ['nn-NO', 'no'],
    'english' => ['en-GB,en;q=0.9', 'en'],
    'english before norwegian' => ['en,nb;q=0.5', 'en'],
    'unsupported language' => ['de-DE,de;q=0.9', 'en'],
]);

test('every unprefixed public page redirects to its localized page', function (string $route) {
    $this->withHeader('Accept-Language', 'nb')
        ->get(route($route))
        ->assertRedirect(route("localized.{$route}", ['locale' => 'no']));
})->with(['home', 'privacy', 'support']);

test('localized pages are served in the language from the url', function (string $locale) {
    $this->withHeader('Accept-Language', 'de')
        ->get(route('localized.home', ['locale' => $locale]))
        ->assertOk()
        ->assertSee('<html lang="'.$locale.'"', false)
        ->assertCookie('locale', $locale)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('locale', $locale)
        );
})->with(['no', 'en']);

test('the last used language overrides the browser preferred language', function () {
    $this->withCookie('locale', 'en')
        ->withHeader('Accept-Language', 'nb-NO')
        ->get(route('home'))
        ->assertRedirect(route('localized.home', ['locale' => 'en']));
});

test('an unsupported locale cookie is ignored', function () {
    $this->withCookie('locale', 'de')
        ->withHeader('Accept-Language', 'nb')
        ->get(route('home'))
        ->assertRedirect(route('localized.home', ['locale' => 'no']));
});

test('unsupported locales in the url are not found', function () {
    $this->get('/de')->assertNotFound();
    $this->get('/de/privacy')->assertNotFound();
});

test('unprefixed links keep the language of the page the visitor came from', function (string $route) {
    $rememberedLocale = $this->withHeader('Accept-Language', 'en')
        ->get(route('localized.home', ['locale' => 'no']))
        ->getCookie('locale')
        ->getValue();

    $this->withCookie('locale', $rememberedLocale)
        ->withHeader('Accept-Language', 'en')
        ->get(route($route))
        ->assertRedirect(route("localized.{$route}", ['locale' => 'no']));
})->with(['privacy', 'support']);
