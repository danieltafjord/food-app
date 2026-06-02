<?php

namespace Database\Factories;

use App\Models\Household;
use App\Models\ShoppingList;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingList>
 */
class ShoppingListFactory extends Factory
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
            'dinner_plan_id' => null,
            'created_by_user_id' => null,
            'name' => fake()->randomElement(['Weekly groceries', 'Dinner shop', 'Quick run']),
        ];
    }
}
