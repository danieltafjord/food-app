<?php

namespace App\Actions\Households;

use App\Data\HouseholdInputData;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateHousehold
{
    /**
     * Create a household, make the creator its owner, and activate it for them.
     */
    public function handle(User $user, HouseholdInputData $data): Household
    {
        return DB::transaction(function () use ($user, $data): Household {
            $household = Household::create(['name' => $data->name]);

            $household->members()->attach($user, ['role' => HouseholdRole::Owner->value]);
            $user->update(['current_household_id' => $household->id]);

            return $household;
        });
    }
}
