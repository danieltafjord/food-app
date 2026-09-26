<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * People who sign up with Apple or Google have no password until they set one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Passwordless accounts get a random password nobody knows, so the column
     * can be required again; they can still reset it by email.
     */
    public function down(): void
    {
        DB::table('users')->whereNull('password')->eachById(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['password' => Hash::make(Str::random(64))]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
