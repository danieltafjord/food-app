<?php

namespace App\Actions\DinnerPlans;

use App\Data\DinnerPlanInputData;
use App\Models\DinnerPlan;
use App\Models\Household;
use App\Models\User;

class CreateDinnerPlan
{
    public function handle(Household $household, DinnerPlanInputData $data, User $creator): DinnerPlan
    {
        return $household->dinnerPlans()->create([
            'name' => $data->name,
            'start_date' => $data->startDate,
            'end_date' => $data->endDate,
            'created_by_user_id' => $creator->id,
        ])->load('entries.dinner');
    }
}
