<?php

use App\Models\Dinner;
use App\Models\Ingredient;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

it('lists ingredients for the active household only', function () {
    Ingredient::factory()->count(3)->for($this->household)->create();
    Ingredient::factory()->create(); // belongs to another household

    $this->getJson('/api/v1/ingredients')
        ->assertSuccessful()
        ->assertJsonCount(3, 'data');
});

it('creates an ingredient in the active household', function () {
    $this->postJson('/api/v1/ingredients', ['name' => 'Tomato', 'default_unit' => 'pcs', 'category' => 'Produce'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Tomato')
        ->assertJsonPath('data.default_unit', 'pcs');

    $this->assertDatabaseHas('ingredients', [
        'household_id' => $this->household->id,
        'name' => 'Tomato',
    ]);
});

it('validates ingredient input', function () {
    $this->postJson('/api/v1/ingredients', ['name' => ''])->assertUnprocessable();
});

it('hides another household ingredient', function () {
    $foreign = Ingredient::factory()->create();

    $this->getJson("/api/v1/ingredients/{$foreign->id}")->assertNotFound();
});

it('updates an ingredient', function () {
    $ingredient = Ingredient::factory()->for($this->household)->create();

    $this->patchJson("/api/v1/ingredients/{$ingredient->id}", ['name' => 'Onion'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Onion');
});

it('deletes an unused ingredient', function () {
    $ingredient = Ingredient::factory()->for($this->household)->create();

    $this->deleteJson("/api/v1/ingredients/{$ingredient->id}")->assertNoContent();
    $this->assertModelMissing($ingredient);
});

it('refuses to delete an ingredient that is in use', function () {
    $ingredient = Ingredient::factory()->for($this->household)->create();
    $dinner = Dinner::factory()->for($this->household)->create();
    $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1, 'unit' => 'pcs']);

    $this->deleteJson("/api/v1/ingredients/{$ingredient->id}")->assertConflict();
    $this->assertModelExists($ingredient);
});

it('requires an active household', function () {
    Passport::actingAs(User::factory()->create());

    $this->getJson('/api/v1/ingredients')->assertConflict();
});
