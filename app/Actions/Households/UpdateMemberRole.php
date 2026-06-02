<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;

class UpdateMemberRole
{
    public function handle(Household $household, User $member, HouseholdRole $role): void
    {
        if ($role !== HouseholdRole::Owner && $household->isOwnedBy($member) && $household->ownerCount() <= 1) {
            abort(409, 'The household must have at least one owner.');
        }

        $household->members()->updateExistingPivot($member->id, ['role' => $role->value]);
    }
}
