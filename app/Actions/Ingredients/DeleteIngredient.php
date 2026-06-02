<?php

namespace App\Actions\Ingredients;

use App\Models\Ingredient;

class DeleteIngredient
{
    /**
     * Delete an ingredient, refusing if it is still referenced by a recipe or
     * shopping list (the database also enforces this for dinner items).
     */
    public function handle(Ingredient $ingredient): void
    {
        if ($ingredient->dinnerItems()->exists() || $ingredient->shoppingListItems()->exists()) {
            abort(409, 'This ingredient is in use and cannot be deleted.');
        }

        $ingredient->delete();
    }
}
