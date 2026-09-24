<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/*
 * Live sync for the mobile app. Devices authorize over the API token
 * (`/api/v1/broadcasting/auth`); every channel is household-scoped and open
 * to that household's members only.
 */

$isMember = fn (User $user, int|string $householdId): bool => $user->households()->whereKey((int) $householdId)->exists();

$presence = fn (User $user): array => ['id' => $user->id, 'name' => $user->name];

// "The household moved to a new sync version": devices pull when they hear it.
Broadcast::channel('household.{householdId}', fn (User $user, string $householdId): bool => $isMember($user, $householdId));

// Who has a shopping list open. The list may exist only on a device so far, so
// membership of the household is the whole check.
Broadcast::channel('household.{householdId}.list.{listId}', fn (User $user, string $householdId, string $listId): array|false => $isMember($user, $householdId) && Str::isUuid($listId) ? $presence($user) : false);

// Who is looking at a week of the dinner plan (keyed by the week's Monday).
Broadcast::channel('household.{householdId}.week.{weekStart}', fn (User $user, string $householdId, string $weekStart): array|false => $isMember($user, $householdId) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) === 1 ? $presence($user) : false);
