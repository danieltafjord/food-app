<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

class DinnerInputData extends Data
{
    /**
     * @param  array<int, DinnerItemInputData>  $items
     */
    public function __construct(
        #[Max(255)]
        public string $name,
        #[Min(1)]
        public int $defaultServings = 2,
        public ?string $notes = null,
        #[DataCollectionOf(DinnerItemInputData::class)]
        public array $items = [],
    ) {}
}
