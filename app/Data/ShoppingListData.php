<?php

namespace App\Data;

use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Spatie\LaravelData\Data;

class ShoppingListData extends Data
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?int $dinnerPlanId,
        public array $items,
    ) {}

    public static function fromList(ShoppingList $list): self
    {
        return new self(
            id: $list->id,
            name: $list->name,
            dinnerPlanId: $list->dinner_plan_id,
            items: $list->items
                ->map(fn (ShoppingListItem $item) => ShoppingListItemData::fromItem($item)->toArray())
                ->all(),
        );
    }
}
