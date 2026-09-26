<?php

namespace App\Enums;

/**
 * The kinds of push notification a user can turn on and off.
 */
enum NotificationTopic: string
{
    /** Someone added items to a shared shopping list. */
    case ListItems = 'list_items';

    /** Someone started or finished a shopping trip. */
    case Shopping = 'shopping';

    /** Someone changed today's or tomorrow's plan, or planned a week. */
    case Plan = 'plan';

    /** Someone joined the household. */
    case Household = 'household';
}
