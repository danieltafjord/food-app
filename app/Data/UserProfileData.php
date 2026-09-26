<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class UserProfileData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $name,
    ) {}
}
