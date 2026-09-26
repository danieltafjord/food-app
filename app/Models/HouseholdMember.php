<?php

namespace App\Models;

use App\Events\HouseholdMemberRemoved;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership of a household (`household_user`), as used by
 * Household::members(). Detaching through that relation deletes these one by
 * one, so a removal (or a member leaving) is announced to the household.
 */
class HouseholdMember extends Pivot
{
    protected $table = 'household_user';

    protected static function booted(): void
    {
        static::deleted(function (self $membership): void {
            event(new HouseholdMemberRemoved((int) $membership->getAttribute('household_id'), (int) $membership->getAttribute('user_id')));
        });
    }
}
