<?php

namespace App\Actions\Dinners;

use App\Data\DinnerInputData;
use App\Models\Dinner;
use App\Models\Household;
use Illuminate\Support\Facades\DB;

class CreateDinner
{
    use ManagesDinnerItems;

    public function handle(Household $household, DinnerInputData $data): Dinner
    {
        $this->assertIngredientsBelongToHousehold($household, $data);

        return DB::transaction(function () use ($household, $data): Dinner {
            $dinner = $household->dinners()->create([
                'name' => $data->name,
                'default_servings' => $data->defaultServings,
                'notes' => $data->notes,
            ]);

            $this->syncItems($dinner, $data);

            return $dinner->load('items.ingredient');
        });
    }
}
