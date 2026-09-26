<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What members did to shared household data, waiting to be bundled into
     * push notifications for the other members.
     */
    public function up(): void
    {
        Schema::create('household_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            // The shopping list (items) — null for plan and household activity.
            $table->unsignedBigInteger('shopping_list_id')->nullable();
            // The item or plan entry the activity is about.
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at', 6);
            $table->timestamp('notified_at')->nullable();

            $table->index(['notified_at', 'created_at']);
            $table->index(['household_id', 'user_id', 'kind', 'notified_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_activities');
    }
};
