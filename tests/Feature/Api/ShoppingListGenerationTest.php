<?php

use App\Models\Dinner;
use App\Models\DinnerPlan;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

it('scales recipe quantities by planned servings and aggregates by ingredient', function () {
    $onion = Ingredient::factory()->for($this->household)->create(['name' => 'Onion']);
    $beef = Ingredient::factory()->for($this->household)->create(['name' => 'Beef']);

    // Bolognese serves 4: 2 onions + 500g beef.
    $bolognese = Dinner::factory()->for($this->household)->create(['default_servings' => 4]);
    $bolognese->items()->createMany([
        ['ingredient_id' => $onion->id, 'quantity' => 2, 'unit' => 'pcs'],
        ['ingredient_id' => $beef->id, 'quantity' => 500, 'unit' => 'g'],
    ]);

    // Soup serves 2: 1 onion.
    $soup = Dinner::factory()->for($this->household)->create(['default_servings' => 2]);
    $soup->items()->create(['ingredient_id' => $onion->id, 'quantity' => 1, 'unit' => 'pcs']);

    $plan = DinnerPlan::factory()->for($this->household)->create();
    // Cook bolognese for 2 (factor 0.5 -> 1 onion, 250g beef) and soup for 2 (factor 1 -> 1 onion).
    $plan->entries()->create(['dinner_id' => $bolognese->id, 'scheduled_date' => '2026-06-09', 'servings' => 2, 'meal_type' => 'dinner']);
    $plan->entries()->create(['dinner_id' => $soup->id, 'scheduled_date' => '2026-06-10', 'servings' => 2, 'meal_type' => 'dinner']);

    $this->postJson("/api/v1/dinner-plans/{$plan->id}/shopping-list")
        ->assertSuccessful()
        ->assertJsonPath('data.dinner_plan_id', $plan->id);

    $list = ShoppingList::firstWhere('dinner_plan_id', $plan->id);

    // Onion: 1 (bolognese scaled) + 1 (soup) = 2 pcs as a single aggregated line; Beef: 250 g.
    expect($list->items()->count())->toBe(2)
        ->and((float) $list->items()->where('ingredient_id', $onion->id)->value('quantity'))->toBe(2.0)
        ->and((float) $list->items()->where('ingredient_id', $beef->id)->value('quantity'))->toBe(250.0);
});

it('keeps the same ingredient in different units as separate lines', function () {
    $flour = Ingredient::factory()->for($this->household)->create();

    $dinner = Dinner::factory()->for($this->household)->create(['default_servings' => 1]);
    $dinner->items()->createMany([
        ['ingredient_id' => $flour->id, 'quantity' => 100, 'unit' => 'g'],
        ['ingredient_id' => $flour->id, 'quantity' => 1, 'unit' => 'cup'],
    ]);

    $plan = DinnerPlan::factory()->for($this->household)->create();
    $plan->entries()->create(['dinner_id' => $dinner->id, 'scheduled_date' => '2026-06-09', 'servings' => 1, 'meal_type' => 'dinner']);

    $this->postJson("/api/v1/dinner-plans/{$plan->id}/shopping-list")->assertSuccessful();

    $list = ShoppingList::firstWhere('dinner_plan_id', $plan->id);
    expect($list->items()->count())->toBe(2);
});

it('forbids generating from another household plan', function () {
    $foreign = DinnerPlan::factory()->create();

    $this->postJson("/api/v1/dinner-plans/{$foreign->id}/shopping-list")->assertNotFound();
});
