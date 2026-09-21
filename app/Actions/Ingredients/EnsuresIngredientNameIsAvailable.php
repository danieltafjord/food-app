<?php

namespace App\Actions\Ingredients;

use App\Models\Household;
use App\Models\Ingredient;
use Illuminate\Validation\ValidationException;

trait EnsuresIngredientNameIsAvailable
{
    /**
     * Names are unique per household among live ingredients (case-insensitive).
     */
    protected function assertNameIsAvailable(Household $household, string $name, ?Ingredient $except = null): void
    {
        $taken = $household->ingredients()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'name' => 'An ingredient with this name already exists.',
            ]);
        }
    }
}
