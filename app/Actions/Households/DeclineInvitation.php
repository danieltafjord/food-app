<?php

namespace App\Actions\Households;

use App\Models\HouseholdInvitation;

class DeclineInvitation
{
    public function handle(HouseholdInvitation $invitation): void
    {
        if (! $invitation->isPending()) {
            abort(409, 'This invitation is no longer valid.');
        }

        $invitation->update(['declined_at' => now()]);
    }
}
