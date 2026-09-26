<?php

namespace App\Enums;

enum AppLocale: string
{
    case English = 'en';
    case Norwegian = 'nb';

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Norwegian => 'Norsk',
        };
    }

    /**
     * The application locale holding this language's translations (lang/no).
     */
    public function translationLocale(): string
    {
        return match ($this) {
            self::English => 'en',
            self::Norwegian => 'no',
        };
    }
}
