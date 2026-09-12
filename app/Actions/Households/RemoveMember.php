<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    /**
     * Remove a member from the household (also used when a member leaves).
     */
    public function handle(Household $household, User $member): void
    {
        DB::transaction(function () use ($household, $member): void {
            // Serialise membership changes per household so two owners leaving at
            // once cannot both pass the last-owner check.
            Household::query()->lockForUpdate()->findOrFail($household->id);

            if ($household->isOwnedBy($member) && $household->ownerCount() <= 1) {
                abort(409, 'The household must have at least one owner.');
            }

            $household->members()->detach($member->id);

            if ($member->current_household_id === $household->id) {
                $member->update(['current_household_id' => null]);
            }
        });
    }
}
