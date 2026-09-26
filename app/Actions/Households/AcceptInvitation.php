<?php

namespace App\Actions\Households;

use App\Actions\Notifications\RecordHouseholdActivity;
use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvitation
{
    public function __construct(private RecordHouseholdActivity $recordActivity) {}

    /**
     * Accept an invitation, joining the user to the household. Whoever holds
     * the token may accept it, whatever their own email address; it only
     * counts while the owner who sent it still owns the household.
     */
    public function handle(HouseholdInvitation $invitation, User $user): Household
    {
        return DB::transaction(function () use ($invitation, $user): Household {
            $household = Household::query()->lockForUpdate()->findOrFail($invitation->household_id);
            $invitation = HouseholdInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            $inviterIsOwner = $invitation->invited_by_user_id !== null && $household->members()
                ->whereKey($invitation->invited_by_user_id)
                ->wherePivot('role', HouseholdRole::Owner->value)
                ->exists();

            if (! $invitation->isPending() || ! $inviterIsOwner) {
                abort(409, __('households.invitation_invalid'));
            }

            if (! $household->hasMember($user)) {
                $household->members()->attach($user, ['role' => $invitation->role->value]);
                $this->recordActivity->memberJoined($household->id, $user->id);
            }

            $invitation->update(['accepted_at' => now()]);

            $user->update(['current_household_id' => $household->id]);

            return $household;
        });
    }
}
