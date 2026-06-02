<?php

namespace Database\Factories;

use App\Models\Dinner;
use App\Models\Household;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dinner>
 */
class DinnerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'household_id' => Household::factory(),
            'created_by_user_id' => null,
            'name' => fake()->words(3, true),
            'default_servings' => fake()->numberBetween(1, 6),
            'notes' => null,
        ];
    }
}
