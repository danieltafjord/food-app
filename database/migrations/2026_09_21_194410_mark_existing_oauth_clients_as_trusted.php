<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clients that exist before dynamic registration was enabled were all
     * created by an administrator, so first-party ones keep skipping consent.
     */
    public function up(): void
    {
        DB::table('oauth_clients')->whereNull('owner_id')->update(['trusted' => true]);
    }

    public function down(): void
    {
        DB::table('oauth_clients')->update(['trusted' => false]);
    }
};
