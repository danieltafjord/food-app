<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['ingredients', 'dinners', 'dinner_items', 'dinner_plans', 'dinner_plan_entries', 'shopping_lists', 'shopping_list_items'];

    private array $withoutCreator = ['ingredients', 'dinner_items', 'dinner_plan_entries', 'shopping_list_items'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->json('content_authors')->nullable();
                $table->unsignedBigInteger('erasure_version')->default(0);
                if (in_array($name, $this->withoutCreator, true)) {
                    $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()->index();
                }
            });
        }
        Schema::table('households', function (Blueprint $table): void {
            $table->json('content_authors')->nullable();
            $table->unsignedBigInteger('erasure_version')->default(0);
        });
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                if (in_array($name, $this->withoutCreator, true)) {
                    $table->dropConstrainedForeignId('created_by_user_id');
                }
                $table->dropColumn(['content_authors', 'erasure_version']);
            });
        }
        Schema::table('households', fn (Blueprint $table) => $table->dropColumn(['content_authors', 'erasure_version']));
    }
};
