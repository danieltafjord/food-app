<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ingredients\CreateIngredient;
use App\Actions\Ingredients\DeleteIngredient;
use App\Actions\Ingredients\UpdateIngredient;
use App\Data\IngredientData;
use App\Data\IngredientInputData;
use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class IngredientController extends ApiController
{
    /**
     * @return DataCollection<int, IngredientData>
     */
    public function index(Request $request): DataCollection
    {
        $ingredients = $this->currentHousehold($request)->ingredients()->orderBy('name')->get();

        return IngredientData::collect($ingredients, DataCollection::class);
    }

    public function store(IngredientInputData $data, Request $request, CreateIngredient $action): IngredientData
    {
        return IngredientData::from($action->handle($this->currentHousehold($request), $data));
    }

    public function show(Request $request, Ingredient $ingredient): IngredientData
    {
        $this->ensureBelongsToHousehold($request, $ingredient);

        return IngredientData::from($ingredient);
    }

    public function update(IngredientInputData $data, Request $request, Ingredient $ingredient, UpdateIngredient $action): IngredientData
    {
        $this->ensureBelongsToHousehold($request, $ingredient);

        return IngredientData::from($action->handle($ingredient, $data));
    }

    public function destroy(Request $request, Ingredient $ingredient, DeleteIngredient $action): Response
    {
        $this->ensureBelongsToHousehold($request, $ingredient);

        $action->handle($ingredient);

        return response()->noContent();
    }
}
