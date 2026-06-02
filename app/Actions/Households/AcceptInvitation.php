<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptInvitation
{
    /**
     * Accept an invitation, joining the user to the household.
     */
    public function handle(HouseholdInvitation $invitation, User $user): Household
    {
        if (! $invitation->isPending()) {
            abort(409, 'This invitation is no longer valid.');
        }

        if (Str::lower($invitation->email) !== Str::lower($user->email)) {
            abort(403, 'This invitation was sent to a different email address.');
        }

        $household = $invitation->household;

        return DB::transaction(function () use ($invitation, $user, $household): Household {
            if (! $household->hasMember($user)) {
                $household->members()->attach($user, ['role' => $invitation->role->value]);
            }

            $invitation->update(['accepted_at' => now()]);

            if (is_null($user->current_household_id)) {
                $user->update(['current_household_id' => $household->id]);
            }

            return $household;
        });
    }
}
