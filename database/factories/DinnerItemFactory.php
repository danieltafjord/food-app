<?php

namespace Database\Factories;

use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DinnerItem>
 */
class DinnerItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dinner_id' => Dinner::factory(),
            'ingredient_id' => fn (array $attributes) => Ingredient::factory()
                ->for(Dinner::withTrashed()->findOrFail($attributes['dinner_id'])->household)
                ->create()
                ->id,
            'quantity' => fake()->randomFloat(2, 1, 500),
            'unit' => fake()->randomElement(['g', 'ml', 'pcs', 'tbsp']),
        ];
    }
}
