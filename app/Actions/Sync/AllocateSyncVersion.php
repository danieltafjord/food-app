<?php

namespace App\Actions\Sync;

use App\Events\HouseholdDataChanged;
use App\Models\Household;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

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
        DB::afterCommit(function () use ($householdId, $version): void {
            unset($this->memo[$householdId]);
            $this->announce($householdId, $version);
        });

        return $version;
    }

    /**
     * Tell the household's other devices that a version committed. The event
     * skips the socket that made the change (its sync response already carries
     * it). It is sent after the response, once per household per request (a
     * later version replaces an earlier one). A broadcast that fails is only
     * reported: the change is committed, and the fallback poll will pick it up.
     */
    private function announce(int $householdId, int $version): void
    {
        $event = (new HouseholdDataChanged($householdId, $version))->dontBroadcastToCurrentUser();

        defer(function () use ($event): void {
            try {
                event($event);
            } catch (Throwable $e) {
                report($e);
            }
        }, 'sync-version-announce-'.$householdId, always: true);
    }

    /** Drop every memoised version (a transaction rolled back, so none of them committed). */
    public function forget(): void
    {
        $this->memo = [];
    }
}
