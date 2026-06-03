<?php

namespace App\Actions\Households;

use App\Data\HouseholdInputData;
use App\Models\Household;

class UpdateHousehold
{
    public function handle(Household $household, HouseholdInputData $data): Household
    {
        $attributes = ['name' => $data->name];

        if ($data->defaultServings !== null) {
            $attributes['default_servings'] = $data->defaultServings;
        }

        $household->update($attributes);

        return $household;
    }
}
