<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Households\SwitchHousehold;
use App\Data\HouseholdData;
use App\Http\Controllers\Controller;
use App\Models\Household;
use Illuminate\Http\Request;

class SwitchHouseholdController extends Controller
{
    /**
     * Set the authenticated user's active household.
     */
    public function __invoke(Request $request, SwitchHousehold $action): HouseholdData
    {
        $validated = $request->validate([
            'household_id' => ['required', 'integer'],
        ]);

        $household = Household::findOrFail($validated['household_id']);

        $this->authorize('view', $household);

        $action->handle($request->user(), $household);

        return HouseholdData::from($household);
    }
}
