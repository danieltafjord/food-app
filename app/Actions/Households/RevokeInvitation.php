<?php

namespace App\Actions\Households;

use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;

class RevokeInvitation
{
    public function handle(HouseholdInvitation $invitation): void
    {
        $invitation->delete();
    }

    /**
     * Revoke the open invitations someone sent to a household, once they no
     * longer own it: an invitation must not let them back in (or hand out
     * ownership) after they were removed or demoted.
     */
    public function sentBy(Household $household, User $inviter): void
    {
        $household->invitations()
            ->where('invited_by_user_id', $inviter->id)
            ->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->delete();
    }
}
