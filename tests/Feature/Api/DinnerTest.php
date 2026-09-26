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

it('caps how many ingredients a recipe can have', function () {
    $ingredient = Ingredient::factory()->for($this->household)->create();
    $dinner = Dinner::factory()->for($this->household)->create();
    $items = array_fill(0, 201, ['ingredient_id' => $ingredient->id, 'quantity' => 1, 'unit' => 'g']);

    $this->postJson('/api/v1/dinners', ['name' => 'Everything', 'default_servings' => 2, 'items' => $items])
        ->assertUnprocessable()->assertJsonValidationErrors('items');
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['items' => $items])
        ->assertUnprocessable()->assertJsonValidationErrors('items');
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

    $this->assertSoftDeleted($dinner);
    $this->assertSoftDeleted($item);
});

it('preserves unspecified fields and ingredients in a partial dinner update', function () {
    $dinner = Dinner::factory()->for($this->household)->create(['name' => 'Old', 'default_servings' => 6, 'notes' => 'Keep']);
    $ingredient = Ingredient::factory()->for($this->household)->create();
    $item = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit' => 'g']);
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['name' => 'New'])
        ->assertSuccessful()->assertJsonPath('data.name', 'New')->assertJsonPath('data.default_servings', 6)
        ->assertJsonPath('data.notes', 'Keep')->assertJsonCount(1, 'data.items');
    expect($item->fresh()->deleted_at)->toBeNull();
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['notes' => null, 'items' => []])
        ->assertSuccessful()->assertJsonPath('data.name', 'New')->assertJsonPath('data.notes', null)
        ->assertJsonPath('data.default_servings', 6)->assertJsonCount(0, 'data.items');
    $this->assertSoftDeleted($item);
});

it('validates supplied fields on partial dinner updates', function (array $payload) {
    $dinner = Dinner::factory()->for($this->household)->create();
    $this->patchJson("/api/v1/dinners/{$dinner->id}", $payload)->assertUnprocessable();
})->with([
    [['name' => null]], [['name' => '']], [['name' => str_repeat('x', 256)]],
    [['default_servings' => 0]], [['default_servings' => 100]], [['items' => null]],
    [['notes' => str_repeat('x', 5001)]],
]);

it('rejects foreign ingredients in a partial dinner update without changing existing data', function () {
    $dinner = Dinner::factory()->for($this->household)->create(['notes' => 'Keep']);
    $foreign = Ingredient::factory()->create();
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['notes' => 'Changed', 'items' => [['ingredient_id' => $foreign->id]]])
        ->assertUnprocessable();
    expect($dinner->fresh()->notes)->toBe('Keep');
});

it('keeps distinct unit rows and their identities when a recipe ingredient set is updated', function () {
    $dinner = Dinner::factory()->for($this->household)->create();
    $ingredient = Ingredient::factory()->for($this->household)->create();
    $grams = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit' => 'g']);
    $cup = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1, 'unit' => 'cup']);
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['items' => [
        ['ingredient_id' => $ingredient->id, 'quantity' => 200, 'unit' => 'g'],
        ['ingredient_id' => $ingredient->id, 'quantity' => 1, 'unit' => 'cup'],
    ]])->assertSuccessful()->assertJsonCount(2, 'data.items');
    expect($dinner->items()->pluck('id')->sort()->values()->all())->toBe([$grams->id, $cup->id])
        ->and((float) $grams->fresh()->quantity)->toBe(200.0);
});

it('creates a dinner with an optional category and exposes it when browsing', function (?string $category) {
    $response = $this->postJson('/api/v1/dinners', ['name' => 'Category dinner', 'category' => $category])
        ->assertSuccessful()->assertJsonPath('data.category', $category);

    $this->assertDatabaseHas('dinners', ['id' => $response->json('data.id'), 'category' => $category]);
    $this->getJson('/api/v1/dinners')->assertSuccessful()->assertJsonPath('data.0.category', $category);
})->with([null, 'meat', 'fish', 'vegetarian', 'other']);

it('preserves omitted categories and explicitly changes or clears them', function () {
    $dinner = Dinner::factory()->for($this->household)->create(['category' => 'fish']);
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['name' => 'Renamed'])
        ->assertSuccessful()->assertJsonPath('data.category', 'fish');
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['category' => 'vegetarian'])
        ->assertSuccessful()->assertJsonPath('data.category', 'vegetarian');
    $this->assertDatabaseHas('dinners', ['id' => $dinner->id, 'category' => 'vegetarian']);
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['category' => null])
        ->assertSuccessful()->assertJsonPath('data.category', null);
    $this->assertDatabaseHas('dinners', ['id' => $dinner->id, 'category' => null]);
});

it('rejects unsupported dinner categories without changing the dinner', function () {
    $dinner = Dinner::factory()->for($this->household)->create(['category' => 'fish']);
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['category' => 'produce'])
        ->assertUnprocessable()->assertJsonValidationErrors('category');
    $this->assertDatabaseHas('dinners', ['id' => $dinner->id, 'category' => 'fish']);
    $this->postJson('/api/v1/dinners', ['name' => 'Invalid category dinner', 'category' => 'produce'])
        ->assertUnprocessable()->assertJsonValidationErrors('category');
    $this->assertDatabaseMissing('dinners', ['name' => 'Invalid category dinner']);
});

it('does not allow changing another household dinner category', function () {
    $dinner = Dinner::factory()->create(['category' => 'fish']);
    $this->patchJson("/api/v1/dinners/{$dinner->id}", ['category' => 'meat'])->assertNotFound();
    $this->assertDatabaseHas('dinners', ['id' => $dinner->id, 'category' => 'fish']);
});
