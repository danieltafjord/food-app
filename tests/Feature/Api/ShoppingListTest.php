<?php

use App\Models\Ingredient;
use App\Models\ShoppingList;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

it('creates a shopping list', function () {
    $this->postJson('/api/v1/shopping-lists', ['name' => 'Weekly shop'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Weekly shop');

    $this->assertDatabaseHas('shopping_lists', [
        'household_id' => $this->household->id,
        'name' => 'Weekly shop',
    ]);
});

it('adds a catalogue item and an ad-hoc item', function () {
    $list = ShoppingList::factory()->for($this->household)->create();
    $ingredient = Ingredient::factory()->for($this->household)->create();

    $this->postJson("/api/v1/shopping-lists/{$list->id}/items", [
        'ingredient_id' => $ingredient->id, 'quantity' => 3, 'unit' => 'pcs',
    ])->assertSuccessful()->assertJsonPath('data.ingredient_id', $ingredient->id);

    $this->postJson("/api/v1/shopping-lists/{$list->id}/items", ['name' => 'Aluminium foil'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Aluminium foil');

    expect($list->items()->count())->toBe(2);
});

it('requires an ingredient or a name for an item', function () {
    $list = ShoppingList::factory()->for($this->household)->create();

    $this->postJson("/api/v1/shopping-lists/{$list->id}/items", ['quantity' => 1])->assertUnprocessable();
});

it('rejects an item ingredient from another household', function () {
    $list = ShoppingList::factory()->for($this->household)->create();
    $foreign = Ingredient::factory()->create();

    $this->postJson("/api/v1/shopping-lists/{$list->id}/items", ['ingredient_id' => $foreign->id])
        ->assertUnprocessable();
});

it('checks and unchecks an item', function () {
    $list = ShoppingList::factory()->for($this->household)->create();
    $item = $list->items()->create(['name' => 'Milk', 'is_checked' => false]);

    $this->patchJson("/api/v1/shopping-lists/{$list->id}/items/{$item->id}/check", ['checked' => true])
        ->assertSuccessful()
        ->assertJsonPath('data.is_checked', true);

    expect($item->fresh()->is_checked)->toBeTrue();
});

it('deletes an item', function () {
    $list = ShoppingList::factory()->for($this->household)->create();
    $item = $list->items()->create(['name' => 'Milk']);

    $this->deleteJson("/api/v1/shopping-lists/{$list->id}/items/{$item->id}")->assertNoContent();
    $this->assertModelMissing($item);
});

it('hides another household shopping list', function () {
    $foreign = ShoppingList::factory()->create();

    $this->getJson("/api/v1/shopping-lists/{$foreign->id}")->assertNotFound();
});

it('deletes a shopping list', function () {
    $list = ShoppingList::factory()->for($this->household)->create();

    $this->deleteJson("/api/v1/shopping-lists/{$list->id}")->assertNoContent();
    $this->assertModelMissing($list);
});
