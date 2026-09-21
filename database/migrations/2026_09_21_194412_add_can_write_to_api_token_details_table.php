<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OAuth-issued tokens carry no read/write scopes, so their permission is
     * copied from the user's grant. Null means "use the token's own scopes".
     */
    public function up(): void
    {
        Schema::table('api_token_details', function (Blueprint $table) {
            $table->boolean('can_write')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_token_details', function (Blueprint $table) {
            $table->dropColumn('can_write');
        });
    }
};
