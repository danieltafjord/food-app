<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DinnerPlans\AddPlanEntry;
use App\Actions\DinnerPlans\UpdatePlanEntry;
use App\Data\DinnerPlanEntryData;
use App\Data\DinnerPlanEntryInputData;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DinnerPlanEntryController extends ApiController
{
    public function store(DinnerPlanEntryInputData $data, Request $request, DinnerPlan $dinnerPlan, AddPlanEntry $action): DinnerPlanEntryData
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);

        return DinnerPlanEntryData::fromEntry($action->handle($dinnerPlan, $data));
    }

    public function update(DinnerPlanEntryInputData $data, Request $request, DinnerPlan $dinnerPlan, DinnerPlanEntry $entry, UpdatePlanEntry $action): DinnerPlanEntryData
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);
        abort_unless($entry->dinner_plan_id === $dinnerPlan->id, 404);

        return DinnerPlanEntryData::fromEntry($action->handle($entry, $data));
    }

    public function destroy(Request $request, DinnerPlan $dinnerPlan, DinnerPlanEntry $entry): Response
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);
        abort_unless($entry->dinner_plan_id === $dinnerPlan->id, 404);

        $entry->delete();

        return response()->noContent();
    }
}
