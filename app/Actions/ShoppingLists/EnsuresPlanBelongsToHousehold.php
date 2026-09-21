<?php

namespace App\Actions\ShoppingLists;

use App\Models\Household;
use Illuminate\Validation\ValidationException;

trait EnsuresPlanBelongsToHousehold
{
    /**
     * A shopping list may only be linked to a plan of its own household.
     */
    protected function assertPlanBelongsToHousehold(Household $household, ?int $planId): void
    {
        if (! is_null($planId) && ! $household->dinnerPlans()->whereKey($planId)->exists()) {
            throw ValidationException::withMessages([
                'dinner_plan_id' => 'That plan does not belong to this household.',
            ]);
        }
    }
}
