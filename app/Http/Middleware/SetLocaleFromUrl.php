<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromUrl
{
    /**
     * Serve the page in the language from the URL and remember it for the visitor's next visit.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');

        App::setLocale($locale);

        if ($request->cookie('locale') !== $locale) {
            Cookie::queue(Cookie::forever('locale', $locale));
        }

        return $next($request);
    }
}
