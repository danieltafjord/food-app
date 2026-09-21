<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\DinnerPlans\CreateDinnerPlan;
use App\Actions\DinnerPlans\UpdateDinnerPlan;
use App\Data\DinnerPlanData;
use App\Data\DinnerPlanInputData;
use App\Models\DinnerPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class DinnerPlanController extends ApiController
{
    /**
     * @return DataCollection<int, DinnerPlanData>
     */
    public function index(Request $request): DataCollection
    {
        $plans = $this->currentHousehold($request)->dinnerPlans()
            ->with('entries.dinner')
            ->latest('start_date')
            ->get()
            ->map(fn (DinnerPlan $plan) => DinnerPlanData::fromPlan($plan));

        return DinnerPlanData::collect($plans, DataCollection::class);
    }

    public function store(DinnerPlanInputData $data, Request $request, CreateDinnerPlan $action): DinnerPlanData
    {
        return DinnerPlanData::fromPlan($action->handle($this->currentHousehold($request), $data, $request->user()));
    }

    public function show(Request $request, DinnerPlan $dinnerPlan): DinnerPlanData
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);

        return DinnerPlanData::fromPlan($dinnerPlan->load('entries.dinner'));
    }

    public function update(DinnerPlanInputData $data, Request $request, DinnerPlan $dinnerPlan, UpdateDinnerPlan $action): DinnerPlanData
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);

        return DinnerPlanData::fromPlan($action->handle($dinnerPlan, $data));
    }

    public function destroy(Request $request, DinnerPlan $dinnerPlan): Response
    {
        $this->ensureBelongsToHousehold($request, $dinnerPlan);

        $dinnerPlan->delete();

        return response()->noContent();
    }
}
