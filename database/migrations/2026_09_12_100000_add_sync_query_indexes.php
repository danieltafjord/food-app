<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the queries the sync endpoint and the REST API actually run.
     *
     * `foreignId()->constrained()` only creates the constraint; Postgres does
     * not index the referencing column, so every child lookup, cascade and
     * `ON DELETE` check was a sequential scan. The sync pull is always
     * `household_id = ? AND sync_version > ?` (parents) or
     * `parent_id IN (…) AND sync_version > ?` (children), so each table gets
     * that composite; it makes the old single-column `sync_version` index
     * redundant, and `synced_at` is informational (never queried), so both go.
     *
     * @var array<string, string> table → its household or parent column
     */
    private array $scopes = [
        'ingredients' => 'household_id',
        'dinners' => 'household_id',
        'dinner_plans' => 'household_id',
        'shopping_lists' => 'household_id',
        'dinner_items' => 'dinner_id',
        'dinner_plan_entries' => 'dinner_plan_id',
        'shopping_list_items' => 'shopping_list_id',
    ];

    /** @var array<string, string> table → secondary foreign key that is looked up on its own */
    private array $lookups = [
        'dinner_items' => 'ingredient_id',
        'dinner_plan_entries' => 'dinner_id',
        'shopping_list_items' => 'ingredient_id',
        'shopping_lists' => 'dinner_plan_id',
        'household_user' => 'user_id',
        'users' => 'current_household_id',
    ];

    public function up(): void
    {
        foreach ($this->scopes as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->index([$column, 'sync_version']);
                $blueprint->dropIndex(['sync_version']);
                $blueprint->dropIndex(['synced_at']);
            });
        }

        foreach ($this->lookups as $table => $column) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column));
        }

        // The case-insensitive same-name merge in the sync batch and
        // IngredientController::assertNameIsAvailable filter on lower(name).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX ingredients_household_id_lower_name_index ON ingredients (household_id, lower(name))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS ingredients_household_id_lower_name_index');
        }

        foreach ($this->lookups as $table => $column) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex([$column]));
        }

        foreach ($this->scopes as $table => $column) {
            Schema::table($table, function (Blueprint $blueprint) use ($column) {
                $blueprint->index('sync_version');
                $blueprint->index('synced_at');
                $blueprint->dropIndex([$column, 'sync_version']);
            });
        }
    }
};
