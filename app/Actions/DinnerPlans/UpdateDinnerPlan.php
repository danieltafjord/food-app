<?php

namespace App\Actions\DinnerPlans;

use App\Data\DinnerPlanInputData;
use App\Models\DinnerPlan;

class UpdateDinnerPlan
{
    public function handle(DinnerPlan $plan, DinnerPlanInputData $data): DinnerPlan
    {
        $plan->update([
            'name' => $data->name,
            'start_date' => $data->startDate,
            'end_date' => $data->endDate,
        ]);

        return $plan->load('entries.dinner');
    }
}
