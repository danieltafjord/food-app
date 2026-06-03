<?php

namespace App\Data;

use App\Enums\AppLocale;
use App\Enums\Theme;
use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $emailVerified,
        public bool $twoFactorEnabled,
        public Theme $theme,
        public AppLocale $locale,
        public ?HouseholdData $currentHousehold,
    ) {}
}
