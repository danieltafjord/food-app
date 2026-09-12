<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replace the timestamp-based sync cursor with a per-household monotonic
     * integer version.
     *
     * Every write to a syncable row (sync batch, REST, cascade) allocates the
     * next `households.sync_version` under a row lock and stamps it on the row,
     * so a client that pulls "everything with sync_version > my cursor" can
     * never miss a write that landed in the same second or committed out of
     * order — the two failure modes of the old microsecond timestamp cursor.
     *
     * Ingredient names also stop being unique at the database level: two
     * devices can create "Melk" offline, and the sync layer merges them by
     * name instead of failing the whole batch on a constraint violation.
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
        Schema::table('households', function (Blueprint $table) {
            $table->unsignedBigInteger('sync_version')->default(0)->after('default_servings');
        });

        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('sync_version')->default(0)->index()->after('synced_at');
            });

            // Rows that predate versioning become version 1 so a client with an
            // old cursor re-pulls them exactly once.
            DB::table($name)->update(['sync_version' => 1]);
        }

        DB::table('households')->update(['sync_version' => 1]);

        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique(['household_id', 'name']);
            $table->index(['household_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'name']);
            $table->unique(['household_id', 'name']);
        });

        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropIndex(['sync_version']);
                $table->dropColumn('sync_version');
            });
        }

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('sync_version');
        });
    }
};
