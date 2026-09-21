<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedirectToPreferredLocaleController extends Controller
{
    /**
     * Browser language codes that should be served the Norwegian locale.
     *
     * @var list<string>
     */
    private const array NORWEGIAN_LANGUAGE_CODES = ['no', 'nb', 'nn'];

    /**
     * Send the visitor to the public page in the language they last used, or their browser's preferred language.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $this->rememberedLocale($request) ?? $this->browserLocale($request);

        return redirect()
            ->route('localized.'.$request->route()->getName(), ['locale' => $locale])
            ->withHeaders(['Vary' => 'Accept-Language, Cookie']);
    }

    private function rememberedLocale(Request $request): ?string
    {
        $locale = $request->cookie('locale');

        return in_array($locale, config('handlelista.locales'), true) ? $locale : null;
    }

    private function browserLocale(Request $request): string
    {
        $language = $request->getPreferredLanguage(['en', ...self::NORWEGIAN_LANGUAGE_CODES]);

        return in_array($language, self::NORWEGIAN_LANGUAGE_CODES, true) ? 'no' : 'en';
    }
}
