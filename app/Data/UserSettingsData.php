<?php

namespace App\Data;

use App\Enums\AppLocale;
use App\Enums\Theme;
use Spatie\LaravelData\Data;

class UserSettingsData extends Data
{
    public function __construct(
        public Theme $theme,
        public AppLocale $locale,
    ) {}
}
