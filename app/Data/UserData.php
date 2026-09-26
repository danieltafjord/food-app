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
        /** False for Apple/Google sign-ups that never set a password. */
        public bool $hasPassword,
        /** True until someone whose provider shared no name has chosen one. */
        public bool $needsName,
        /** @var list<string> Apple/Google identities linked to the account. */
        public array $signInProviders,
        public Theme $theme,
        public AppLocale $locale,
        public ?HouseholdData $currentHousehold,
    ) {}
}
