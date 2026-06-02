<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

class DinnerItemInputData extends Data
{
    public function __construct(
        public int $ingredientId,
        #[Min(0)]
        public ?float $quantity = null,
        #[Max(50)]
        public ?string $unit = null,
    ) {}
}
