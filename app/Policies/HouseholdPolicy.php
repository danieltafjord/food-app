<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    /**
     * Any member may view the household.
     */
    public function view(User $user, Household $household): bool
    {
        return $household->hasMember($user);
    }

    /**
     * Only owners may rename the household.
     */
    public function update(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    /**
     * Only owners may delete the household.
     */
    public function delete(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }

    /**
     * Only owners may manage members and invitations.
     */
    public function manage(User $user, Household $household): bool
    {
        return $household->isOwnedBy($user);
    }
}
