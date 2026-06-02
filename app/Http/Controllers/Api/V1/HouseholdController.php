<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Households\CreateHousehold;
use App\Actions\Households\DeleteHousehold;
use App\Actions\Households\UpdateHousehold;
use App\Data\HouseholdData;
use App\Data\HouseholdInputData;
use App\Data\HouseholdMembershipData;
use App\Http\Controllers\Controller;
use App\Models\Household;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class HouseholdController extends Controller
{
    /**
     * List the households the authenticated user belongs to, with their role.
     */
    public function index(Request $request): DataCollection
    {
        $households = $request->user()->households()->get()
            ->map(fn (Household $household) => HouseholdMembershipData::fromHousehold($household));

        return HouseholdMembershipData::collect($households, DataCollection::class);
    }

    public function store(HouseholdInputData $data, Request $request, CreateHousehold $action): HouseholdData
    {
        return HouseholdData::from($action->handle($request->user(), $data));
    }

    public function show(Request $request, Household $household): HouseholdData
    {
        $this->authorize('view', $household);

        return HouseholdData::from($household);
    }

    public function update(HouseholdInputData $data, Household $household, UpdateHousehold $action): HouseholdData
    {
        $this->authorize('update', $household);

        return HouseholdData::from($action->handle($household, $data));
    }

    public function destroy(Household $household, DeleteHousehold $action): Response
    {
        $this->authorize('delete', $household);

        $action->handle($household);

        return response()->noContent();
    }
}
