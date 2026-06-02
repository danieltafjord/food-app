<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\DinnerPlanData;
use App\Data\DinnerPlanInputData;
use App\Models\DinnerPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class DinnerPlanController extends ApiController
{
    public function index(Request $request): DataCollection
    {
        $plans = $this->currentHousehold($request)->dinnerPlans()
            ->with('entries.dinner')
            ->latest('start_date')
            ->get()
            ->map(fn (DinnerPlan $plan) => DinnerPlanData::fromPlan($plan));

        return DinnerPlanData::collect($plans, DataCollection::class);
    }

    public function store(DinnerPlanInputData $data, Request $request): DinnerPlanData
    {
        $plan = $this->currentHousehold($request)->dinnerPlans()->create([
            'name' => $data->name,
            'start_date' => $data->startDate,
            'end_date' => $data->endDate,
            'created_by_user_id' => $request->user()->id,
        ]);

        return DinnerPlanData::fromPlan($plan->load('entries.dinner'));
    }

    public function show(Request $request, DinnerPlan $dinnerPlan): DinnerPlanData
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);

        return DinnerPlanData::fromPlan($dinnerPlan->load('entries.dinner'));
    }

    public function update(DinnerPlanInputData $data, Request $request, DinnerPlan $dinnerPlan): DinnerPlanData
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);

        $dinnerPlan->update([
            'name' => $data->name,
            'start_date' => $data->startDate,
            'end_date' => $data->endDate,
        ]);

        return DinnerPlanData::fromPlan($dinnerPlan->load('entries.dinner'));
    }

    public function destroy(Request $request, DinnerPlan $dinnerPlan): Response
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);

        $dinnerPlan->delete();

        return response()->noContent();
    }
}
