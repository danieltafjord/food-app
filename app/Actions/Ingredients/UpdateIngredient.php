<?php

namespace App\Actions\Ingredients;

use App\Data\IngredientInputData;
use App\Models\Ingredient;

class UpdateIngredient
{
    use EnsuresIngredientNameIsAvailable;

    public function handle(Ingredient $ingredient, IngredientInputData $data): Ingredient
    {
        $this->assertNameIsAvailable($ingredient->household, $data->name, $ingredient);

        $ingredient->update([
            'name' => $data->name,
            'default_unit' => $data->defaultUnit,
            'category' => $data->category,
            'category_source' => 'user',
        ]);

        return $ingredient;
    }
}
