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
}
