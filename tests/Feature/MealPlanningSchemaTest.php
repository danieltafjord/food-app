<?php

use App\Enums\HouseholdRole;
use App\Enums\MealType;
use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Database\QueryException;

it('links users to a household with pivot roles', function () {
    $household = Household::factory()->create();
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $household->members()->attach([
        $owner->id => ['role' => HouseholdRole::Owner->value],
        $member->id => ['role' => HouseholdRole::Member->value],
    ]);

    expect($household->members)->toHaveCount(2)
        ->and($household->members->firstWhere('id', $owner->id)->pivot->role)->toBe('owner')
        ->and($owner->households)->toHaveCount(1);
});

it('tracks a current household for a user', function () {
    $household = Household::factory()->create();
    $user = User::factory()->create(['current_household_id' => $household->id]);

    expect($user->currentHousehold->is($household))->toBeTrue();
});

it('builds a dinner with ingredient items', function () {
    $household = Household::factory()->create();
    $ingredient = Ingredient::factory()->for($household)->create();

    $dinner = Dinner::factory()->for($household)->create(['default_servings' => 4]);
    $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 250, 'unit' => 'g']);

    expect($dinner->items)->toHaveCount(1)
        ->and($dinner->ingredients->first()->is($ingredient))->toBeTrue()
        ->and($dinner->items->first()->ingredient->is($ingredient))->toBeTrue();
});

it('defaults a dinner to two servings', function () {
    expect((new Dinner)->default_servings)->toBe(2);
});

it('casts the meal type to an enum and defaults to dinner', function () {
    $entry = DinnerPlanEntry::factory()->create();

    expect($entry->meal_type)->toBe(MealType::Dinner);
});

it('schedules dinners inside a plan', function () {
    $household = Household::factory()->create();
    $plan = DinnerPlan::factory()->for($household)->create();
    $dinner = Dinner::factory()->for($household)->create();

    $plan->entries()->create([
        'dinner_id' => $dinner->id,
        'scheduled_date' => now(),
        'servings' => 2,
    ]);

    expect($plan->entries)->toHaveCount(1)
        ->and($plan->entries->first()->dinner->is($dinner))->toBeTrue();
});

it('supports catalogue and ad-hoc shopping list items', function () {
    $household = Household::factory()->create();
    $ingredient = Ingredient::factory()->for($household)->create();
    $list = ShoppingList::factory()->for($household)->create();

    $list->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 3, 'unit' => 'pcs']);
    $list->items()->create(['name' => 'Aluminium foil', 'quantity' => 1, 'unit' => 'pcs']);

    expect($list->items)->toHaveCount(1 + 1)
        ->and($list->items->firstWhere('name', 'Aluminium foil')->ingredient)->toBeNull()
        ->and($list->items->first()->is_checked)->toBeFalse();
});

it('cascades deletes from a dinner to its items and plan entries', function () {
    $household = Household::factory()->create();
    $ingredient = Ingredient::factory()->for($household)->create();
    $dinner = Dinner::factory()->for($household)->create();
    $item = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit' => 'g']);
    $entry = DinnerPlanEntry::factory()->for($dinner)->create();

    $dinner->delete();

    expect(DinnerItem::find($item->id))->toBeNull()
        ->and(DinnerPlanEntry::find($entry->id))->toBeNull()
        ->and(Ingredient::find($ingredient->id))->not->toBeNull();
});

it('cascades deletes from a household to everything it owns', function () {
    $household = Household::factory()->create();
    $dinner = Dinner::factory()->for($household)->create();
    $plan = DinnerPlan::factory()->for($household)->create();
    $list = ShoppingList::factory()->for($household)->create();

    $household->delete();

    expect(Dinner::find($dinner->id))->toBeNull()
        ->and(DinnerPlan::find($plan->id))->toBeNull()
        ->and(ShoppingList::find($list->id))->toBeNull();
});

it('prevents deleting an ingredient still used by a dinner', function () {
    $household = Household::factory()->create();
    $ingredient = Ingredient::factory()->for($household)->create();
    $dinner = Dinner::factory()->for($household)->create();
    $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit' => 'g']);

    $ingredient->delete();
})->throws(QueryException::class);
