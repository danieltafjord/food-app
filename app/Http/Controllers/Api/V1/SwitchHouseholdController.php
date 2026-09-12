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

        // Resolve through the user's own memberships so a foreign id reads exactly
        // like a missing one — no probing which household ids exist.
        /** @var Household $household */
        $household = $request->user()->households()->findOrFail($validated['household_id']);

        $action->handle($request->user(), $household);

        return HouseholdData::from($household);
    }
}
