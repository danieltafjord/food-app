<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\User;

class SwitchHousehold
{
    public function handle(User $user, Household $household): void
    {
        $user->update(['current_household_id' => $household->id]);
    }
}
