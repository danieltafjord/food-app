<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('household_id')->nullable()->constrained()->nullOnDelete();
            $table->string('feature', 32);
            $table->string('model', 120);
            $table->string('status', 16);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 8)->default(0);
            $table->timestamp('created_at');
            $table->index(['created_at', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_requests');
    }
};
