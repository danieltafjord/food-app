<?php

namespace App\Actions\Dinners;

use App\Data\DinnerInputData;
use App\Models\Dinner;
use App\Models\Household;
use App\Models\User;
use App\Rules\DinnerCategoryReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateDinner
{
    use ManagesDinnerItems;

    public function handle(Household $household, DinnerInputData $data, ?User $creator = null): Dinner
    {
        Validator::make(['category' => $data->category], ['category' => ['nullable', 'string', new DinnerCategoryReference($household->id)]])->validate();
        $this->assertIngredientsBelongToHousehold($household, $data);

        return DB::transaction(function () use ($household, $data, $creator): Dinner {
            $dinner = $household->dinners()->create([
                'created_by_user_id' => $creator?->id,
                'name' => $data->name,
                'default_servings' => $data->defaultServings,
                'notes' => $data->notes,
                'category' => $data->category,
            ]);

            $this->syncItems($dinner, $data);

            return $dinner->load('items.ingredient');
        });
    }
}
