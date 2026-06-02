<?php

namespace Database\Seeders;

use App\Enums\HouseholdRole;
use App\Enums\MealType;
use App\Models\Household;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FoodSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed a household with a recipe catalogue, a weekly plan, and a shopping list.
     */
    public function run(): void
    {
        $household = Household::factory()->create(['name' => 'Our Household']);

        $owner = User::factory()->create(['name' => 'Daniel', 'email' => 'daniel@example.com']);
        $partner = User::factory()->create(['name' => 'Partner', 'email' => 'partner@example.com']);

        $household->members()->attach([
            $owner->id => ['role' => HouseholdRole::Owner->value],
            $partner->id => ['role' => HouseholdRole::Member->value],
        ]);

        $owner->update(['current_household_id' => $household->id]);
        $partner->update(['current_household_id' => $household->id]);

        $ingredients = collect([
            ['name' => 'Spaghetti', 'default_unit' => 'g', 'category' => 'Pantry'],
            ['name' => 'Minced beef', 'default_unit' => 'g', 'category' => 'Meat'],
            ['name' => 'Tomato', 'default_unit' => 'pcs', 'category' => 'Produce'],
            ['name' => 'Onion', 'default_unit' => 'pcs', 'category' => 'Produce'],
            ['name' => 'Garlic', 'default_unit' => 'clove', 'category' => 'Produce'],
            ['name' => 'Olive oil', 'default_unit' => 'tbsp', 'category' => 'Pantry'],
            ['name' => 'Chicken breast', 'default_unit' => 'g', 'category' => 'Meat'],
            ['name' => 'Rice', 'default_unit' => 'g', 'category' => 'Pantry'],
            ['name' => 'Bell pepper', 'default_unit' => 'pcs', 'category' => 'Produce'],
            ['name' => 'Soy sauce', 'default_unit' => 'tbsp', 'category' => 'Pantry'],
        ])->mapWithKeys(function (array $attributes) use ($household) {
            $ingredient = $household->ingredients()->create($attributes);

            return [$attributes['name'] => $ingredient];
        });

        $bolognese = $household->dinners()->create([
            'created_by_user_id' => $owner->id,
            'name' => 'Spaghetti Bolognese',
            'default_servings' => 4,
        ]);

        $bolognese->items()->createMany([
            ['ingredient_id' => $ingredients['Spaghetti']->id, 'quantity' => 400, 'unit' => 'g'],
            ['ingredient_id' => $ingredients['Minced beef']->id, 'quantity' => 500, 'unit' => 'g'],
            ['ingredient_id' => $ingredients['Tomato']->id, 'quantity' => 4, 'unit' => 'pcs'],
            ['ingredient_id' => $ingredients['Onion']->id, 'quantity' => 1, 'unit' => 'pcs'],
            ['ingredient_id' => $ingredients['Garlic']->id, 'quantity' => 2, 'unit' => 'clove'],
        ]);

        $stirFry = $household->dinners()->create([
            'created_by_user_id' => $partner->id,
            'name' => 'Chicken Stir-fry',
            'default_servings' => 2,
        ]);

        $stirFry->items()->createMany([
            ['ingredient_id' => $ingredients['Chicken breast']->id, 'quantity' => 300, 'unit' => 'g'],
            ['ingredient_id' => $ingredients['Rice']->id, 'quantity' => 150, 'unit' => 'g'],
            ['ingredient_id' => $ingredients['Bell pepper']->id, 'quantity' => 2, 'unit' => 'pcs'],
            ['ingredient_id' => $ingredients['Soy sauce']->id, 'quantity' => 3, 'unit' => 'tbsp'],
        ]);

        $plan = $household->dinnerPlans()->create([
            'created_by_user_id' => $owner->id,
            'name' => 'This week',
            'start_date' => now()->startOfWeek(),
            'end_date' => now()->endOfWeek(),
        ]);

        $plan->entries()->createMany([
            [
                'dinner_id' => $bolognese->id,
                'scheduled_date' => now()->startOfWeek(),
                'servings' => 2,
                'meal_type' => MealType::Dinner,
            ],
            [
                'dinner_id' => $stirFry->id,
                'scheduled_date' => now()->startOfWeek()->addDay(),
                'servings' => 2,
                'meal_type' => MealType::Dinner,
            ],
        ]);

        $list = $household->shoppingLists()->create([
            'dinner_plan_id' => $plan->id,
            'created_by_user_id' => $owner->id,
            'name' => 'This week',
        ]);

        $list->items()->createMany([
            ['ingredient_id' => $ingredients['Spaghetti']->id, 'quantity' => 200, 'unit' => 'g'],
            ['ingredient_id' => $ingredients['Minced beef']->id, 'quantity' => 250, 'unit' => 'g'],
            ['ingredient_id' => $ingredients['Chicken breast']->id, 'quantity' => 300, 'unit' => 'g'],
            ['name' => 'Aluminium foil', 'quantity' => 1, 'unit' => 'pcs'],
        ]);
    }
}
