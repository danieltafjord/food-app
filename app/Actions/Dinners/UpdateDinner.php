<?php

namespace App\Actions\Dinners;

use App\Data\DinnerInputData;
use App\Data\DinnerUpdateData;
use App\Models\Dinner;
use Illuminate\Support\Facades\DB;
use Spatie\LaravelData\Optional;

class UpdateDinner
{
    use ManagesDinnerItems;

    public function handle(Dinner $dinner, DinnerInputData|DinnerUpdateData $data): Dinner
    {
        if (! $data->items instanceof Optional) {
            $this->assertIngredientsBelongToHousehold($dinner->household, $data);
        }

        return DB::transaction(function () use ($dinner, $data): Dinner {
            $attributes = array_filter([
                'name' => $data->name,
                'default_servings' => $data->defaultServings,
                'notes' => $data->notes,
            ], fn ($value) => ! $value instanceof Optional);
            $dinner->update($attributes);

            if (! $data->items instanceof Optional) {
                $this->syncItems($dinner, $data);
            }

            return $dinner->load('items.ingredient');
        });
    }
}
