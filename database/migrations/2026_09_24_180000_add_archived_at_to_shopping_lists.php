<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Finished lists can be archived instead of deleted: they leave the lists
     * screen (and the device's in-memory store) but stay in the household.
     * Microsecond precision like the other synced timestamps.
     */
    public function up(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table): void {
            $table->timestamp('archived_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shopping_lists', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
