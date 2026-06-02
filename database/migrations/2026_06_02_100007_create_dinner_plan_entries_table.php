<?php

use App\Enums\MealType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dinner_plan_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dinner_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dinner_id')->constrained()->cascadeOnDelete();
            $table->date('scheduled_date');
            $table->unsignedSmallInteger('servings');
            $table->string('meal_type')->default(MealType::Dinner->value);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('scheduled_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dinner_plan_entries');
    }
};
