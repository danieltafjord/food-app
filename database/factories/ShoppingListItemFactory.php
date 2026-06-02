<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShoppingListItem>
 */
class ShoppingListItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shopping_list_id' => ShoppingList::factory(),
            'ingredient_id' => Ingredient::factory(),
            'name' => null,
            'quantity' => fake()->randomFloat(2, 1, 10),
            'unit' => fake()->randomElement(['g', 'pcs', 'ml']),
            'is_checked' => false,
        ];
    }
}
