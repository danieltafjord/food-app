<?php

namespace App\Actions\Dinners;

use App\Data\DinnerInputData;
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
    protected function assertIngredientsBelongToHousehold(Household $household, DinnerInputData $data): void
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
     * Reconcile the dinner's items with the supplied set, keyed by ingredient.
     *
     * Existing rows keep their identity (and uuid) when their ingredient is
     * still present, so synced devices see an edit rather than a delete plus a
     * re-create; rows whose ingredient is gone are tombstoned. A duplicated
     * ingredient in the payload keeps its first occurrence.
     */
    protected function syncItems(Dinner $dinner, DinnerInputData $data): void
    {
        /** @var Collection<int, DinnerItem> $existing */
        $existing = $dinner->items()->get()->keyBy('ingredient_id');
        $seen = [];

        foreach ($data->items as $item) {
            if (isset($seen[$item->ingredientId])) {
                continue;
            }
            $seen[$item->ingredientId] = true;

            $current = $existing->get($item->ingredientId);
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
