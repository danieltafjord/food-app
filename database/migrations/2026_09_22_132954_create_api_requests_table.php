<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('channel', 16);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('api_token_detail_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->string('route', 120)->nullable();
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('request_body')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at');
            $table->index(['created_at']);
            $table->index(['channel', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
    }
};
