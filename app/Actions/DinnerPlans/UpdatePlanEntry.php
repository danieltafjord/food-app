<?php

namespace App\Actions\DinnerPlans;

use App\Data\DinnerPlanEntryInputData;
use App\Models\DinnerPlanEntry;

class UpdatePlanEntry
{
    use ManagesPlanEntries;

    public function handle(DinnerPlanEntry $entry, DinnerPlanEntryInputData $data): DinnerPlanEntry
    {
        $this->assertDinnerBelongsToHousehold($entry->dinnerPlan->household, $data->dinnerId);

        $entry->update([
            'dinner_id' => $data->dinnerId,
            'scheduled_date' => $data->scheduledDate,
            'servings' => $data->servings,
            'meal_type' => $data->mealType,
            'notes' => $data->notes,
        ]);

        return $entry->load('dinner');
    }
}
