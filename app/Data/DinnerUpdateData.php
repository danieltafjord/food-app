<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class DinnerUpdateData extends Data
{
    /** @param array<int, DinnerItemInputData>|Optional $items */
    public function __construct(
        #[Max(255)]
        public string|Optional $name = new Optional,
        #[Min(1), Max(99)]
        public int|Optional $defaultServings = new Optional,
        #[Max(5000)]
        public string|null|Optional $notes = new Optional,
        #[DataCollectionOf(DinnerItemInputData::class)]
        public array|Optional $items = new Optional,
        /** Built-in category slug or a household custom category UUID. */
        #[Max(36)]
        public string|null|Optional $category = new Optional,
    ) {}
}
