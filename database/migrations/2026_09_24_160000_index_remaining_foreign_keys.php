<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index every foreign key that had none.
     *
     * Postgres does not index a referencing column, so each `ON DELETE`
     * (cascade or set null) scans the whole referencing table: deleting an
     * account scanned every content table for `created_by_user_id`, and
     * deleting a household or token scanned the request logs — inside the
     * transaction that holds the household lock. The admin log filters by
     * user and household sort newest first, hence the `id` suffix there.
     *
     * @var array<string, list<string|list<string>>> table → columns to index
     */
    private array $indexes = [
        'ingredients' => ['created_by_user_id'],
        'dinners' => ['created_by_user_id'],
        'dinner_items' => ['created_by_user_id'],
        'dinner_plans' => ['created_by_user_id'],
        'dinner_plan_entries' => ['created_by_user_id'],
        'shopping_lists' => ['created_by_user_id'],
        'shopping_list_items' => ['created_by_user_id'],
        'dinner_categories' => ['created_by_user_id'],
        'household_invitations' => ['invited_by_user_id'],
        'api_token_details' => ['household_id'],
        'oauth_household_grants' => ['client_id', 'household_id'],
        'admin_actions' => ['admin_id'],
        'dinner_images' => ['household_id', 'user_id'],
        'api_requests' => ['household_id', 'api_token_detail_id'],
        'ai_requests' => [['user_id', 'id'], ['household_id', 'id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns) {
                foreach ($columns as $column) {
                    $blueprint->dropIndex(is_array($column) ? $column : [$column]);
                }
            });
        }
    }
};
