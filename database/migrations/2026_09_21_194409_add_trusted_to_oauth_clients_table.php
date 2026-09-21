<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trusted clients (our own mobile app) skip the consent screen and get
     * full mobile-API tokens. Everything else, including dynamically
     * registered MCP clients, must be approved and pinned to a household.
     */
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->boolean('trusted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('trusted');
        });
    }
};
