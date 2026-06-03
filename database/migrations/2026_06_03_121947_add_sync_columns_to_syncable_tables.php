<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Tables the mobile client syncs offline. Each gains:
     *  - `uuid`       — the client-generated stable identity (the public id in the
     *                   sync API), so offline-created rows have a fixed key.
     *  - `synced_at`  — server-authoritative delta cursor: stamped on every sync
     *                   write so other devices can pull "everything since last sync".
     *  - `deleted_at` — tombstone marker so deletes propagate (the sync layer keeps
     *                   the row; the existing REST endpoints still hard-delete).
     *
     * `created_at` / `updated_at` keep the client's authoring time (last-write-wins),
     * which is distinct from the server `synced_at` cursor.
     *
     * @var list<string>
     */
    private array $tables = [
        'ingredients',
        'dinners',
        'dinner_items',
        'dinner_plans',
        'dinner_plan_entries',
        'shopping_lists',
        'shopping_list_items',
    ];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->uuid('uuid')->nullable()->after('id');
                // Microsecond precision so the delta cursor never misses two
                // writes that land in the same second.
                $table->timestamp('synced_at', 6)->nullable()->after('updated_at');
                $table->softDeletes();
            });

            // Backfill identities for any rows that predate sync.
            foreach (DB::table($name)->whereNull('uuid')->pluck('id') as $id) {
                DB::table($name)->where('id', $id)->update(['uuid' => (string) Str::uuid()]);
            }

            Schema::table($name, function (Blueprint $table) {
                $table->unique('uuid');
                $table->index('synced_at');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropUnique(['uuid']);
                $table->dropIndex(['synced_at']);
                $table->dropColumn(['uuid', 'synced_at', 'deleted_at']);
            });
        }
    }
};
