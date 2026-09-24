<?php

namespace App\Data;

use App\Data\OpenApi\ItemsOf;
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
        #[ItemsOf(ShoppingListItemData::class)]
        public array $items,
        public ?string $archivedAt = null,
    ) {}

    public static function fromList(ShoppingList $list): self
    {
        return new self(
            id: $list->id,
            name: $list->name,
            dinnerPlanId: $list->dinner_plan_id,
            archivedAt: $list->archived_at?->toISOString(),
            items: $list->items
                ->map(fn (ShoppingListItem $item) => ShoppingListItemData::fromItem($item)->toArray())
                ->all(),
        );
    }
}
