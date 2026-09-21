<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * List every public page in every language for search engines.
     */
    public function __invoke(): Response
    {
        $pages = collect(['home', 'privacy', 'support'])
            ->map(fn (string $page): array => PublicPageController::alternateUrls($page));

        return response()
            ->view('sitemap', ['pages' => $pages])
            ->header('Content-Type', 'application/xml');
    }
}
