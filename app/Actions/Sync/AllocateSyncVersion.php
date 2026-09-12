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
 *
 * Within one enclosing transaction (a sync batch, a REST request wrapped by
 * `WrapWritesInTransaction`, an action's `DB::transaction`) every write shares
 * a single version: the rows commit atomically, so a client sees all of them
 * or none, and one lock cycle replaces one per saved model. The memo is
 * dropped when that transaction commits (`afterCommit`) or rolls back (see
 * AppServiceProvider); with no enclosing transaction `afterCommit` runs at
 * once, so separately committed saves never share a number.
 *
 * Registered as a singleton so the memo spans the whole request.
 */
class AllocateSyncVersion
{
    /** @var array<int, int> household id → version allocated in the open transaction */
    private array $memo = [];

    public function handle(int $householdId): int
    {
        if (isset($this->memo[$householdId])) {
            return $this->memo[$householdId];
        }

        $version = DB::transaction(function () use ($householdId): int {
            $household = Household::query()->lockForUpdate()->findOrFail($householdId);
            $household->increment('sync_version');

            return (int) $household->sync_version;
        });

        $this->memo[$householdId] = $version;
        DB::afterCommit(function () use ($householdId): void {
            unset($this->memo[$householdId]);
        });

        return $version;
    }

    /** Drop every memoised version (a transaction rolled back, so none of them committed). */
    public function forget(): void
    {
        $this->memo = [];
    }
}
