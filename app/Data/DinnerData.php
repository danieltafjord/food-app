<?php

namespace App\Data;

use App\Models\Dinner;
use App\Models\DinnerItem;
use Spatie\LaravelData\Data;

class DinnerData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $defaultServings,
        public ?string $notes,
        public array $items,
    ) {}

    public static function fromDinner(Dinner $dinner): self
    {
        return new self(
            id: $dinner->id,
            name: $dinner->name,
            defaultServings: $dinner->default_servings,
            notes: $dinner->notes,
            items: $dinner->items->map(fn (DinnerItem $item) => DinnerItemData::fromItem($item)->toArray())->all(),
        );
    }
}
