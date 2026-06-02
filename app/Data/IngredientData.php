<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class IngredientData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $defaultUnit,
        public ?string $category,
    ) {}
}
