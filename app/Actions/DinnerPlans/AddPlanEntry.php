<?php

namespace App\Actions\DinnerPlans;

use App\Data\DinnerPlanEntryInputData;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;

class AddPlanEntry
{
    use ManagesPlanEntries;

    public function handle(DinnerPlan $plan, DinnerPlanEntryInputData $data): DinnerPlanEntry
    {
        $this->assertDinnerBelongsToHousehold($plan->household, $data->dinnerId);

        return $plan->entries()->create([
            'dinner_id' => $data->dinnerId,
            'scheduled_date' => $data->scheduledDate,
            'servings' => $data->servings,
            'meal_type' => $data->mealType,
            'notes' => $data->notes,
        ])->load('dinner');
    }
}
