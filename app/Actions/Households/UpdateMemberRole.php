<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateMemberRole
{
    public function __construct(private RevokeInvitation $revokeInvitation) {}

    public function handle(Household $household, User $member, HouseholdRole $role): void
    {
        DB::transaction(function () use ($household, $member, $role): void {
            // Serialise with RemoveMember so the last-owner check cannot race.
            Household::query()->lockForUpdate()->findOrFail($household->id);

            if ($role !== HouseholdRole::Owner && $household->isOwnedBy($member) && $household->ownerCount() <= 1) {
                abort(409, __('households.must_keep_owner'));
            }

            $household->members()->updateExistingPivot($member->id, ['role' => $role->value]);

            if ($role !== HouseholdRole::Owner) {
                $this->revokeInvitation->sentBy($household, $member);
            }
        });
    }
}
