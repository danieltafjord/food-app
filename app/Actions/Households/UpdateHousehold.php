<?php

namespace App\Actions\Households;

use App\Data\HouseholdInputData;
use App\Models\Household;

class UpdateHousehold
{
    public function handle(Household $household, HouseholdInputData $data): Household
    {
        $household->update(['name' => $data->name]);

        return $household;
    }
}
