<?php

namespace App\Actions\Sync;

use App\Models\Household;
use Illuminate\Support\Facades\DB;

/**
 * Hand out the next sync version for a household.
 *
 * The household row is locked for the rest of the surrounding transaction, so
 * concurrent writers (two devices syncing, a REST edit racing a sync batch)
 * serialise per household and every version is allocated exactly once, in
 * commit order. That is what makes `sync_version > cursor` a complete delta.
 */
class AllocateSyncVersion
{
    public function handle(int $householdId): int
    {
        return DB::transaction(function () use ($householdId): int {
            $household = Household::query()->lockForUpdate()->findOrFail($householdId);
            $household->increment('sync_version');

            return (int) $household->sync_version;
        });
    }
}
