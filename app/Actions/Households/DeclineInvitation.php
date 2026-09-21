<?php

namespace App\Actions\Households;

use App\Models\HouseholdInvitation;
use Illuminate\Support\Facades\DB;

class DeclineInvitation
{
    public function handle(HouseholdInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $invitation = HouseholdInvitation::query()->lockForUpdate()->findOrFail($invitation->id);

            if (! $invitation->isPending()) {
                abort(409, 'This invitation is no longer valid.');
            }

            $invitation->update(['declined_at' => now()]);
        });
    }
}
