<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_daily_usage', function (Blueprint $table): void {
            $table->date('day');
            $table->string('scope', 64);
            $table->string('feature', 32);
            $table->unsignedInteger('used')->default(0);
            $table->primary(['day', 'scope', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_daily_usage');
    }
};
