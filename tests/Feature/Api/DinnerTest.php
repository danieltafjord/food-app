<?php

use App\Models\Dinner;
use App\Models\Ingredient;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

it('creates a dinner with nested ingredient items', function () {
    $spaghetti = Ingredient::factory()->for($this->household)->create();
    $beef = Ingredient::factory()->for($this->household)->create();

    $this->postJson('/api/v1/dinners', [
        'name' => 'Bolognese',
        'default_servings' => 4,
        'items' => [
            ['ingredient_id' => $spaghetti->id, 'quantity' => 400, 'unit' => 'g'],
            ['ingredient_id' => $beef->id, 'quantity' => 500, 'unit' => 'g'],
        ],
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Bolognese')
        ->assertJsonCount(2, 'data.items');

    expect(Dinner::firstWhere('name', 'Bolognese')->items)->toHaveCount(2);
});

it('rejects items whose ingredient belongs to another household', function () {
    $foreign = Ingredient::factory()->create();

    $this->postJson('/api/v1/dinners', [
        'name' => 'X',
        'default_servings' => 2,
        'items' => [['ingredient_id' => $foreign->id, 'quantity' => 1, 'unit' => 'g']],
    ])->assertUnprocessable();
});

it('lists and shows dinners with their items', function () {
    $dinner = Dinner::factory()->for($this->household)->create();
    $ingredient = Ingredient::factory()->for($this->household)->create();
    $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 10, 'unit' => 'g']);

    $this->getJson('/api/v1/dinners')->assertSuccessful()->assertJsonCount(1, 'data');

    $this->getJson("/api/v1/dinners/{$dinner->id}")
        ->assertSuccessful()
        ->assertJsonPath('data.items.0.ingredient_name', $ingredient->name);
});

it('updates a dinner and replaces its items', function () {
    $dinner = Dinner::factory()->for($this->household)->create();
    $original = Ingredient::factory()->for($this->household)->create();
    $dinner->items()->create(['ingredient_id' => $original->id, 'quantity' => 1, 'unit' => 'g']);
    $replacement = Ingredient::factory()->for($this->household)->create();

    $this->patchJson("/api/v1/dinners/{$dinner->id}", [
        'name' => 'Updated',
        'default_servings' => 3,
        'items' => [['ingredient_id' => $replacement->id, 'quantity' => 2, 'unit' => 'pcs']],
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Updated')
        ->assertJsonCount(1, 'data.items');

    expect($dinner->fresh()->items()->count())->toBe(1)
        ->and($dinner->items()->first()->ingredient_id)->toBe($replacement->id);
});

it('hides another household dinner', function () {
    $foreign = Dinner::factory()->create();

    $this->getJson("/api/v1/dinners/{$foreign->id}")->assertNotFound();
});

it('deletes a dinner and its items', function () {
    $dinner = Dinner::factory()->for($this->household)->create();
    $ingredient = Ingredient::factory()->for($this->household)->create();
    $item = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1, 'unit' => 'g']);

    $this->deleteJson("/api/v1/dinners/{$dinner->id}")->assertNoContent();

    $this->assertModelMissing($dinner);
    $this->assertModelMissing($item);
});
