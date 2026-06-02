<?php

namespace App\Actions\Dinners;

use App\Data\DinnerInputData;
use App\Models\Dinner;
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
     * Replace the dinner's items with the supplied set.
     */
    protected function syncItems(Dinner $dinner, DinnerInputData $data): void
    {
        $dinner->items()->delete();

        foreach ($data->items as $item) {
            $dinner->items()->create([
                'ingredient_id' => $item->ingredientId,
                'quantity' => $item->quantity,
                'unit' => $item->unit,
            ]);
        }
    }
}
