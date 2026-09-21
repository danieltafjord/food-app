<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('ai_categorization_enabled')->default(false);
            $table->boolean('ai_suggestions_enabled')->default(false);
        });
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->string('category_source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['ai_categorization_enabled', 'ai_suggestions_enabled']);
        });
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropColumn('category_source');
        });
    }
};
