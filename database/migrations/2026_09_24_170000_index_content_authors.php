<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Account deletion looks for every row a user contributed to, on every
     * content table: `content_authors ? 'user_N'`. As `json` that is a full
     * scan re-parsing each row, inside the transaction that locks the user's
     * households. As `jsonb` with a GIN index it is an index lookup. SQLite
     * (tests, local) has neither and keeps the plain column.
     *
     * @var list<string>
     */
    private array $tables = [
        'households', 'ingredients', 'dinner_categories', 'dinners', 'dinner_items',
        'dinner_plans', 'dinner_plan_entries', 'shopping_lists', 'shopping_list_items',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN content_authors TYPE jsonb USING content_authors::jsonb");
            DB::statement("CREATE INDEX {$table}_content_authors_index ON {$table} USING gin (content_authors)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->tables as $table) {
            DB::statement("DROP INDEX IF EXISTS {$table}_content_authors_index");
            DB::statement("ALTER TABLE {$table} ALTER COLUMN content_authors TYPE json USING content_authors::json");
        }
    }
};
