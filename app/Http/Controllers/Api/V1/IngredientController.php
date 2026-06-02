<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ingredients\DeleteIngredient;
use App\Data\IngredientData;
use App\Data\IngredientInputData;
use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class IngredientController extends ApiController
{
    public function index(Request $request): DataCollection
    {
        $ingredients = $this->currentHousehold($request)->ingredients()->orderBy('name')->get();

        return IngredientData::collect($ingredients, DataCollection::class);
    }

    public function store(IngredientInputData $data, Request $request): IngredientData
    {
        $ingredient = $this->currentHousehold($request)->ingredients()->create([
            'name' => $data->name,
            'default_unit' => $data->defaultUnit,
            'category' => $data->category,
        ]);

        return IngredientData::from($ingredient);
    }

    public function show(Request $request, Ingredient $ingredient): IngredientData
    {
        $this->ensureBelongsToHousehold($request, $ingredient);

        return IngredientData::from($ingredient);
    }

    public function update(IngredientInputData $data, Request $request, Ingredient $ingredient): IngredientData
    {
        $this->ensureBelongsToHousehold($request, $ingredient);

        $ingredient->update([
            'name' => $data->name,
            'default_unit' => $data->defaultUnit,
            'category' => $data->category,
        ]);

        return IngredientData::from($ingredient);
    }

    public function destroy(Request $request, Ingredient $ingredient, DeleteIngredient $action): Response
    {
        $this->ensureBelongsToHousehold($request, $ingredient);

        $action->handle($ingredient);

        return response()->noContent();
    }
}
