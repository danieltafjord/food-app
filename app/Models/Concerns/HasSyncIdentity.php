<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Gives a syncable model its client-facing identity and tombstone columns.
 *
 * `uuid` is the stable identity the mobile client creates offline and that the
 * sync API exposes as the row's `id`. `synced_at` is the server-side delta
 * cursor and `deleted_at` is the tombstone marker — both managed by the sync
 * layer (see App\Actions\Sync\ApplySyncBatch); the REST endpoints ignore them.
 */
trait HasSyncIdentity
{
    public static function bootHasSyncIdentity(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }

    public function initializeHasSyncIdentity(): void
    {
        $this->mergeFillable(['uuid']);
        $this->mergeCasts([
            'synced_at' => 'datetime:Y-m-d H:i:s.u',
            'deleted_at' => 'datetime',
        ]);
    }
}
