<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromAcceptLanguage
{
    /**
     * Language codes the app may send that should be answered in Norwegian.
     *
     * @var list<string>
     */
    private const array NORWEGIAN_LANGUAGE_CODES = ['nb', 'no', 'nn'];

    /**
     * Answer API requests in the language the app asks for (it sends `nb` or
     * `en`), falling back to the language saved on the signed-in account.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (filled($request->header('Accept-Language'))) {
            $language = $request->getPreferredLanguage(['en', ...self::NORWEGIAN_LANGUAGE_CODES]);

            App::setLocale(in_array($language, self::NORWEGIAN_LANGUAGE_CODES, true) ? 'no' : 'en');
        } elseif (($user = $request->user('api')) !== null) {
            App::setLocale($user->locale?->translationLocale() ?? App::getLocale());
        }

        return $next($request);
    }
}
