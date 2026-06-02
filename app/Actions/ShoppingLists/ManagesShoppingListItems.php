<?php

namespace App\Actions\ShoppingLists;

use App\Data\ShoppingListItemInputData;
use App\Models\Household;
use Illuminate\Validation\ValidationException;

trait ManagesShoppingListItems
{
    /**
     * Validate an item: it must name an ingredient or carry a free-text name,
     * and any referenced ingredient must belong to the household.
     */
    protected function assertItemIsValid(Household $household, ShoppingListItemInputData $data): void
    {
        if (is_null($data->ingredientId) && blank($data->name)) {
            throw ValidationException::withMessages([
                'name' => 'Provide an ingredient or an item name.',
            ]);
        }

        if (! is_null($data->ingredientId) && ! $household->ingredients()->whereKey($data->ingredientId)->exists()) {
            throw ValidationException::withMessages([
                'ingredient_id' => 'That ingredient does not belong to this household.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function itemAttributes(ShoppingListItemInputData $data): array
    {
        return [
            'ingredient_id' => $data->ingredientId,
            'name' => $data->name,
            'quantity' => $data->quantity,
            'unit' => $data->unit,
            'is_checked' => $data->isChecked,
        ];
    }
}
