<?php

namespace App\Models\Concerns;

use App\Actions\Sync\AllocateSyncVersion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Makes a model part of the mobile client's offline sync.
 *
 * Every syncable row carries:
 *  - `uuid`         — the client-generated identity the sync API exposes as `id`.
 *  - `sync_version` — the household-scoped, monotonically increasing version
 *                     stamped on every write (see AllocateSyncVersion). Clients
 *                     pull "everything with sync_version > my cursor".
 *  - `synced_at`    — when the server last wrote the row; informational only.
 *  - `deleted_at`   — deletes are soft so they propagate as tombstones. The
 *                     REST endpoints see only live rows through the SoftDeletes
 *                     scope; the sync layer reads `withTrashed()`.
 *
 * Any write path — the sync batch, a REST controller, a cascade — stamps a
 * version automatically via the `saving` hook, unless the sync layer already
 * assigned the batch's shared version through `stampSync()`.
 */
trait Syncable
{
    use SoftDeletes;

    /** True while the sync layer has assigned this save's version itself. */
    protected bool $syncVersionAssigned = false;

    /** The household whose version clock this row belongs to. */
    abstract public function syncHouseholdId(): int;

    public static function bootSyncable(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });

        static::saving(function (self $model): void {
            if ($model->syncVersionAssigned) {
                $model->syncVersionAssigned = false;

                return;
            }
            if ($model->exists && ! $model->isDirty()) {
                return;
            }
            $model->stampSync(app(AllocateSyncVersion::class)->handle($model->syncHouseholdId()));
        });

        // A REST delete has stamped its version in `runSoftDelete()` below; the
        // children follow under the same version.
        static::registerModelEvent('trashed', function (self $model): void {
            $model->tombstoneChildren($model->getAttribute('deleted_at'), (int) $model->getAttribute('sync_version'));
        });
    }

    /**
     * SoftDeletes writes `deleted_at` with a direct query, bypassing `saving`.
     * Stamp the version in that same UPDATE (one statement, not two) so the
     * tombstone and its version become visible together.
     */
    protected function runSoftDelete(): void
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();
        $version = app(AllocateSyncVersion::class)->handle($this->syncHouseholdId());

        $columns = [
            $this->getDeletedAtColumn() => $this->fromDateTime($time),
            'sync_version' => $version,
            'synced_at' => $this->fromDateTime($time),
        ];

        $this->{$this->getDeletedAtColumn()} = $time;
        $this->setAttribute('sync_version', $version);
        $this->setAttribute('synced_at', $time);

        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;
            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));

        $this->fireModelEvent('trashed', false);
    }

    public function initializeSyncable(): void
    {
        $this->mergeFillable(['uuid']);
        $this->mergeCasts([
            'sync_version' => 'integer',
            'synced_at' => 'datetime',
            'deleted_at' => 'datetime',
        ]);
    }

    /**
     * Assign the version (and sync time) for the save that follows, telling the
     * `saving` hook not to allocate one of its own.
     */
    public function stampSync(int $version, ?CarbonInterface $at = null): static
    {
        $this->setAttribute('sync_version', $version);
        $this->setAttribute('synced_at', $at ?? now());
        $this->syncVersionAssigned = true;

        return $this;
    }

    /**
     * Mark the row deleted at the given client time under an already-allocated
     * version, then cascade to children. Used by the sync batch (where the whole
     * batch shares one version) and by parent cascades.
     */
    public function tombstone(CarbonInterface $deletedAt, int $version): void
    {
        if ($this->trashed()) {
            return;
        }

        $this->setAttribute('deleted_at', $deletedAt);
        $this->setAttribute('updated_at', $deletedAt);
        $this->stampSync($version);
        static::withoutTimestamps(fn () => $this->save());

        $this->tombstoneChildren($deletedAt, $version);
    }

    /**
     * Tombstone dependent rows so peers never keep live children of a deleted
     * parent. Override in models that own children.
     */
    protected function tombstoneChildren(CarbonInterface $deletedAt, int $version): void {}

    /**
     * The columns a bulk child tombstone writes — what `tombstone()` sets on a
     * single model, as one UPDATE over a relation (live rows only, via the
     * SoftDeletes scope).
     *
     * @return array<string, mixed>
     */
    protected function tombstoneStamp(CarbonInterface $deletedAt, int $version): array
    {
        return [
            'deleted_at' => $deletedAt,
            'updated_at' => $deletedAt,
            'sync_version' => $version,
            'synced_at' => now(),
        ];
    }
}
