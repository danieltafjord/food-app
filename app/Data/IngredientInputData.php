<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Data;

class IngredientInputData extends Data
{
    public function __construct(
        #[Max(255)]
        public string $name,
        #[Max(50)]
        public ?string $defaultUnit = null,
        #[Max(50)]
        public ?string $category = null,
    ) {}
}
