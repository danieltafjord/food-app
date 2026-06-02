<?php

namespace App\Actions\Households;

use App\Models\Household;

class DeleteHousehold
{
    /**
     * Delete a household. Related records cascade, and members who had it
     * active have their current_household_id nulled by the foreign key.
     */
    public function handle(Household $household): void
    {
        $household->delete();
    }
}
