<?php

namespace App\Enums;

/**
 * Something a member did to shared household data that other members may
 * want a push notification about.
 */
enum HouseholdActivityKind: string
{
    case ItemAdded = 'item_added';
    case ItemChecked = 'item_checked';
    case PlanEntryAdded = 'plan_entry_added';
    case PlanEntryChanged = 'plan_entry_changed';
    case PlanEntryRemoved = 'plan_entry_removed';
    case MemberJoined = 'member_joined';

    public function isPlan(): bool
    {
        return in_array($this, [self::PlanEntryAdded, self::PlanEntryChanged, self::PlanEntryRemoved], true);
    }
}
