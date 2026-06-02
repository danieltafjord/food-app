<?php

namespace App\Actions\DinnerPlans;

use App\Models\Household;
use Illuminate\Validation\ValidationException;

trait ManagesPlanEntries
{
    /**
     * Ensure the dinner being scheduled belongs to the plan's household.
     */
    protected function assertDinnerBelongsToHousehold(Household $household, int $dinnerId): void
    {
        if (! $household->dinners()->whereKey($dinnerId)->exists()) {
            throw ValidationException::withMessages([
                'dinner_id' => 'That dinner does not belong to this household.',
            ]);
        }
    }
}
