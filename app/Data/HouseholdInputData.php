<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

class HouseholdInputData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $name,
        /** Omitted (null) on a name-only update so the stored value is left untouched. */
        #[Min(1)]
        #[Max(99)]
        public ?int $defaultServings = null,
    ) {}
}
