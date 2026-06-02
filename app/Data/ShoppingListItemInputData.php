<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

class ShoppingListItemInputData extends Data
{
    public function __construct(
        public ?int $ingredientId = null,
        #[Max(255)]
        public ?string $name = null,
        #[Min(0)]
        public ?float $quantity = null,
        #[Max(50)]
        public ?string $unit = null,
        public bool $isChecked = false,
    ) {}
}
