<?php

namespace App\Actions\Dinners;

use App\Actions\ShoppingLists\GenerateShoppingListFromPlan;
use App\Data\DinnerInputData;
use App\Data\DinnerUpdateData;
use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\Household;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

trait ManagesDinnerItems
{
    /**
     * Ensure every referenced ingredient belongs to the household.
     */
    protected function assertIngredientsBelongToHousehold(Household $household, DinnerInputData|DinnerUpdateData $data): void
    {
        $ingredientIds = (new Collection($data->items))->pluck('ingredientId')->unique();

        if ($ingredientIds->isEmpty()) {
            return;
        }

        $ownedCount = $household->ingredients()->whereKey($ingredientIds->all())->count();

        if ($ownedCount !== $ingredientIds->count()) {
            throw ValidationException::withMessages([
                'items' => 'One or more ingredients do not belong to this household.',
            ]);
        }
    }

    /**
     * Reconcile the dinner's items with the supplied set, keyed by ingredient and unit.
     *
     * Existing rows keep their identity (and uuid) when their ingredient is
     * still present, so synced devices see an edit rather than a delete plus a
     * re-create; rows whose ingredient is gone are tombstoned. A duplicated
     * ingredient/unit in the payload keeps its first occurrence.
     */
    protected function syncItems(Dinner $dinner, DinnerInputData|DinnerUpdateData $data): void
    {
        /** @var Collection<int, DinnerItem> $existing */
        $existing = $dinner->items()->get()->keyBy(fn (DinnerItem $item) => $item->ingredient_id.'|'.(GenerateShoppingListFromPlan::normalizeUnit($item->unit) ?? ''));
        $seen = [];

        foreach ($data->items as $item) {
            $key = $item->ingredientId.'|'.(GenerateShoppingListFromPlan::normalizeUnit($item->unit) ?? '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $current = $existing->get($key);
            if ($current !== null) {
                $current->update(['quantity' => $item->quantity, 'unit' => $item->unit]);
            } else {
                $dinner->items()->create([
                    'ingredient_id' => $item->ingredientId,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit,
                ]);
            }
        }

        foreach ($existing as $ingredientId => $current) {
            if (! isset($seen[$ingredientId])) {
                $current->delete();
            }
        }
    }
}
