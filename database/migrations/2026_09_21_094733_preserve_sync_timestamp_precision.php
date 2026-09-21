<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
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
        $this->changePrecision(6);
    }

    public function down(): void
    {
        $this->changePrecision(0);
    }

    private function changePrecision(int $precision): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($precision): void {
                $table->timestamp('created_at', $precision)->nullable()->change();
                $table->timestamp('updated_at', $precision)->nullable()->change();
                $table->timestamp('deleted_at', $precision)->nullable()->change();
            });
        }
    }
};
