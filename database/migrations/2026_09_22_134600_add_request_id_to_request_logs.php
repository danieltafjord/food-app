<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_requests', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->after('id')->index();
        });
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->uuid('request_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('api_requests', function (Blueprint $table): void {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
        });
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropIndex(['request_id']);
            $table->dropColumn('request_id');
        });
    }
};
