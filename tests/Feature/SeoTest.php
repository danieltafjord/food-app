<?php

test('public pages render search engine metadata on the server', function (string $route, string $locale, string $title) {
    $url = route("localized.{$route}", ['locale' => $locale]);

    $this->get($url)
        ->assertOk()
        ->assertSee("<title>{$title}</title>", false)
        ->assertSee('<meta name="description" content="', false)
        ->assertSee('<link rel="canonical" href="'.$url.'">', false)
        ->assertSee('<link rel="alternate" hreflang="no" href="'.route("localized.{$route}", ['locale' => 'no']).'">', false)
        ->assertSee('<link rel="alternate" hreflang="en" href="'.route("localized.{$route}", ['locale' => 'en']).'">', false)
        ->assertSee('<link rel="alternate" hreflang="x-default" href="'.route($route).'">', false)
        ->assertSee('<meta property="og:title" content="'.$title.'">', false)
        ->assertSee('<meta property="og:image" content="'.asset('og-image.png').'">', false)
        ->assertSee('<script type="application/ld+json">{"@context":"https://schema.org","@type":"SoftwareApplication"', false)
        ->assertDontSee('noindex', false);
})->with([
    'norwegian home' => ['home', 'no', 'Handlelista – middagsplan og felles handleliste'],
    'english home' => ['home', 'en', 'Handlelista – meal plans and a shared shopping list'],
    'norwegian privacy' => ['privacy', 'no', 'Personvernerklæring | Handlelista'],
    'english privacy' => ['privacy', 'en', 'Privacy policy | Handlelista'],
    'norwegian support' => ['support', 'no', 'Hjelp | Handlelista'],
    'english support' => ['support', 'en', 'Support | Handlelista'],
]);

test('pages that are not public are kept out of search engines', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex">', false)
        ->assertDontSee('rel="canonical"', false);
});

test('the sitemap lists every public page in every language', function () {
    $response = $this->get(route('sitemap'))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml');

    foreach (['home', 'privacy', 'support'] as $page) {
        foreach (['no', 'en'] as $locale) {
            $response->assertSee('<loc>'.route("localized.{$page}", ['locale' => $locale]).'</loc>', false);
        }

        $response->assertSee('hreflang="x-default" href="'.route($page).'"', false);
    }

    expect(simplexml_load_string($response->getContent())->url)->toHaveCount(6);
});

test('robots.txt points to the sitemap', function () {
    expect(file_get_contents(public_path('robots.txt')))
        ->toContain('Sitemap: https://handlelistaapp.no/sitemap.xml');
});
