<?php

namespace App\Actions\Dinners;

use App\Data\DinnerInputData;
use App\Models\Dinner;
use Illuminate\Support\Facades\DB;

class UpdateDinner
{
    use ManagesDinnerItems;

    public function handle(Dinner $dinner, DinnerInputData $data): Dinner
    {
        $this->assertIngredientsBelongToHousehold($dinner->household, $data);

        return DB::transaction(function () use ($dinner, $data): Dinner {
            $dinner->update([
                'name' => $data->name,
                'default_servings' => $data->defaultServings,
                'notes' => $data->notes,
            ]);

            $this->syncItems($dinner, $data);

            return $dinner->load('items.ingredient');
        });
    }
}
