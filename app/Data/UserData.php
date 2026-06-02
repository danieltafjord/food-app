<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public bool $emailVerified,
        public bool $twoFactorEnabled,
        public ?HouseholdData $currentHousehold,
    ) {}
}
