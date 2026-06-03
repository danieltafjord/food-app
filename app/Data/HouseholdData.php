<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class HouseholdData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int $defaultServings,
    ) {}
}
