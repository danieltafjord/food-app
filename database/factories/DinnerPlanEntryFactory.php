<?php

namespace Database\Factories;

use App\Enums\MealType;
use App\Models\Dinner;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DinnerPlanEntry>
 */
class DinnerPlanEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dinner_plan_id' => DinnerPlan::factory(),
            'dinner_id' => Dinner::factory(),
            'scheduled_date' => fake()->dateTimeBetween('now', '+1 week'),
            'servings' => fake()->numberBetween(1, 6),
            'meal_type' => MealType::Dinner,
            'notes' => null,
        ];
    }
}
