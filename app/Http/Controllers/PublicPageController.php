<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class PublicPageController extends Controller
{
    /**
     * Show the landing page.
     */
    public function home(): Response
    {
        return Inertia::render('Welcome', [
            'seo' => $this->seo('home'),
        ]);
    }

    /**
     * Show the privacy policy.
     */
    public function privacy(): Response
    {
        return Inertia::render('Privacy', [
            'seo' => $this->seo('privacy'),
            'contact' => config('handlelista.contact'),
        ]);
    }

    /**
     * Show the support page.
     */
    public function support(): Response
    {
        return Inertia::render('Support', [
            'seo' => $this->seo('support'),
            'contact' => config('handlelista.contact'),
        ]);
    }

    /**
     * Build the search engine metadata for a public page in the current language.
     *
     * @return array{title: string, description: string, canonical: string, alternates: array<string, string>}
     */
    private function seo(string $page): array
    {
        $alternates = self::alternateUrls($page);

        return [
            'title' => __("seo.{$page}.title"),
            'description' => __("seo.{$page}.description"),
            'canonical' => $alternates[app()->getLocale()],
            'alternates' => $alternates,
        ];
    }

    /**
     * List the URL of a public page for every language, plus the language-neutral default.
     *
     * @return array<string, string>
     */
    public static function alternateUrls(string $page): array
    {
        return collect(config('handlelista.locales'))
            ->mapWithKeys(fn (string $locale): array => [$locale => route("localized.{$page}", ['locale' => $locale])])
            ->put('x-default', route($page))
            ->all();
    }
}
