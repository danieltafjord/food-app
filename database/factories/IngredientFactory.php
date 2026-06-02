<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'default_unit' => fake()->randomElement(['g', 'kg', 'ml', 'l', 'pcs', 'tbsp', 'tsp']),
            'category' => fake()->randomElement(['Produce', 'Dairy', 'Meat', 'Pantry', 'Frozen', 'Bakery']),
        ];
    }
}
