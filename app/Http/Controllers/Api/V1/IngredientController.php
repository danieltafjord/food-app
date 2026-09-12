<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ingredients\DeleteIngredient;
use App\Data\IngredientData;
use App\Data\IngredientInputData;
use App\Models\Household;
use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
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
        $this->assertNameIsAvailable($this->currentHousehold($request), $data->name);

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
        $this->assertNameIsAvailable($this->currentHousehold($request), $data->name, $ingredient);

        $ingredient->update([
            'name' => $data->name,
            'default_unit' => $data->defaultUnit,
            'category' => $data->category,
        ]);

        return IngredientData::from($ingredient);
    }

    /**
     * Names are unique per household among live ingredients (case-insensitive).
     */
    private function assertNameIsAvailable(Household $household, string $name, ?Ingredient $except = null): void
    {
        $taken = $household->ingredients()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => 'An ingredient with this name already exists.',
            ]);
        }
    }

    public function destroy(Request $request, Ingredient $ingredient, DeleteIngredient $action): Response
    {
        $this->ensureBelongsToHousehold($request, $ingredient);

        $action->handle($ingredient);

        return response()->noContent();
    }
}
