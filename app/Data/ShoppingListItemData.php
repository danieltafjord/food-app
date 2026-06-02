<?php

namespace App\Data;

use App\Models\ShoppingListItem;
use Spatie\LaravelData\Data;

class ShoppingListItemData extends Data
{
    public function __construct(
        public int $id,
        public ?int $ingredientId,
        public ?string $ingredientName,
        public ?string $name,
        public ?string $quantity,
        public ?string $unit,
        public bool $isChecked,
    ) {}

    public static function fromItem(ShoppingListItem $item): self
    {
        return new self(
            id: $item->id,
            ingredientId: $item->ingredient_id,
            ingredientName: $item->ingredient?->name,
            name: $item->name,
            quantity: $item->quantity,
            unit: $item->unit,
            isChecked: $item->is_checked,
        );
    }
}
