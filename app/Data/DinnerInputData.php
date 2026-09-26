<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

class DinnerInputData extends Data
{
    /** Ingredients a recipe can have. */
    public const MAX_ITEMS = 200;

    /**
     * @param  array<int, DinnerItemInputData>  $items
     */
    public function __construct(
        #[Max(255)]
        public string $name,
        #[Min(1), Max(99)]
        public int $defaultServings = 2,
        #[Max(5000)]
        public ?string $notes = null,
        /** At most MAX_ITEMS ingredients, well inside a sync batch. */
        #[DataCollectionOf(DinnerItemInputData::class), Max(DinnerInputData::MAX_ITEMS)]
        public array $items = [],
        /** Built-in category slug or a household custom category UUID. */
        #[Max(36)]
        public ?string $category = null,
    ) {}
}
