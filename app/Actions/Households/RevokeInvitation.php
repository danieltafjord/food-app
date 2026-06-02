<?php

namespace App\Actions\Households;

use App\Models\HouseholdInvitation;

class RevokeInvitation
{
    public function handle(HouseholdInvitation $invitation): void
    {
        $invitation->delete();
    }
}
