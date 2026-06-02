<?php

namespace App\Data;

use App\Models\DinnerItem;
use Spatie\LaravelData\Data;

class DinnerItemData extends Data
{
    public function __construct(
        public int $id,
        public int $ingredientId,
        public ?string $ingredientName,
        public ?string $quantity,
        public ?string $unit,
    ) {}

    public static function fromItem(DinnerItem $item): self
    {
        return new self(
            id: $item->id,
            ingredientId: $item->ingredient_id,
            ingredientName: $item->ingredient?->name,
            quantity: $item->quantity,
            unit: $item->unit,
        );
    }
}
