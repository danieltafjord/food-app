<?php

namespace App\Actions\Dinners;

use App\Data\DinnerInputData;
use App\Data\DinnerUpdateData;
use App\Models\Dinner;
use App\Rules\DinnerCategoryReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\LaravelData\Optional;

class UpdateDinner
{
    use ManagesDinnerItems;

    public function handle(Dinner $dinner, DinnerInputData|DinnerUpdateData $data): Dinner
    {
        if (! $data->category instanceof Optional) {
            Validator::make(['category' => $data->category], ['category' => ['nullable', 'string', new DinnerCategoryReference($dinner->household_id)]])->validate();
        }
        if (! $data->items instanceof Optional) {
            $this->assertIngredientsBelongToHousehold($dinner->household, $data);
        }

        return DB::transaction(function () use ($dinner, $data): Dinner {
            $attributes = array_filter([
                'name' => $data->name,
                'default_servings' => $data->defaultServings,
                'notes' => $data->notes,
                'category' => $data->category,
            ], fn ($value) => ! $value instanceof Optional);
            $dinner->update($attributes);

            if (! $data->items instanceof Optional) {
                $this->syncItems($dinner, $data);
            }

            return $dinner->load('items.ingredient');
        });
    }
}
