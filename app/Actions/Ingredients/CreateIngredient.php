<?php

namespace App\Actions\Ingredients;

use App\Data\IngredientInputData;
use App\Models\Household;
use App\Models\Ingredient;

class CreateIngredient
{
    use EnsuresIngredientNameIsAvailable;

    public function handle(Household $household, IngredientInputData $data): Ingredient
    {
        $this->assertNameIsAvailable($household, $data->name);

        return $household->ingredients()->create([
            'name' => $data->name,
            'default_unit' => $data->defaultUnit,
            'category' => $data->category,
            'category_source' => 'user',
        ]);
    }
}
