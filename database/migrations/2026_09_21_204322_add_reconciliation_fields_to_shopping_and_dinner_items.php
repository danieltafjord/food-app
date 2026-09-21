<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dinner_items', function (Blueprint $table) {
            $table->uuid('merged_into_uuid')->nullable();
        });
        Schema::table('shopping_list_items', function (Blueprint $table) {
            // Existing rows have unknown provenance and are preserved as manual items.
            $table->boolean('is_generated')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('dinner_items', fn (Blueprint $table) => $table->dropColumn('merged_into_uuid'));
        Schema::table('shopping_list_items', fn (Blueprint $table) => $table->dropColumn('is_generated'));
    }
};
