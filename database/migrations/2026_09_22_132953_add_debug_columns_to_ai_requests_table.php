<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->json('request')->nullable()->after('cost');
            $table->json('response')->nullable()->after('request');
            $table->text('error')->nullable()->after('response');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_requests', function (Blueprint $table): void {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['request', 'response', 'error']);
        });
    }
};
