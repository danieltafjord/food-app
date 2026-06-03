<?php

use App\Models\Dinner;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

/**
 * Build a syncable row in the client's shape with sensible timestamp defaults.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function syncRow(array $attributes): array
{
    $ts = now()->toISOString();

    return array_merge([
        'id' => (string) Str::uuid(),
        'created_at' => $ts,
        'updated_at' => $ts,
        'deleted_at' => null,
    ], $attributes);
}

it('pushes a full batch, resolving foreign keys and scoping to the household', function () {
    $ingredient = syncRow(['name' => 'Beef', 'default_unit' => 'g', 'category' => 'Meat']);
    $dinner = syncRow(['name' => 'Bolognese', 'default_servings' => 4, 'notes' => null]);
    $item = syncRow([
        'dinner_id' => $dinner['id'],
        'ingredient_id' => $ingredient['id'],
        'quantity' => 500,
        'unit' => 'g',
    ]);

    $response = $this->postJson('/api/v1/sync', [
        'last_sync' => null,
        'changes' => [
            'ingredients' => [$ingredient],
            'dinners' => [$dinner],
            'dinner_items' => [$item],
        ],
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure(['server_time', 'changes' => ['ingredients', 'dinners', 'dinner_items']])
        ->assertJsonPath('changes.dinner_items.0.dinner_id', $dinner['id'])
        ->assertJsonPath('changes.dinner_items.0.ingredient_id', $ingredient['id']);

    $this->assertDatabaseHas('ingredients', [
        'uuid' => $ingredient['id'],
        'household_id' => $this->household->id,
        'name' => 'Beef',
    ]);

    $dinnerModel = Dinner::firstWhere('uuid', $dinner['id']);
    $ingredientModel = Ingredient::firstWhere('uuid', $ingredient['id']);

    // The child's UUID foreign keys resolved to the parents' internal ids.
    $this->assertDatabaseHas('dinner_items', [
        'uuid' => $item['id'],
        'dinner_id' => $dinnerModel->id,
        'ingredient_id' => $ingredientModel->id,
    ]);
});

it('rejects a row whose foreign key references nothing', function () {
    $orphan = syncRow([
        'dinner_id' => (string) Str::uuid(),
        'ingredient_id' => (string) Str::uuid(),
        'quantity' => 1,
        'unit' => 'g',
    ]);

    $this->postJson('/api/v1/sync', [
        'last_sync' => null,
        'changes' => ['dinner_items' => [$orphan]],
    ])->assertStatus(422);
});

it('keeps the newest version under last-write-wins', function () {
    $id = (string) Str::uuid();

    $push = fn (string $name, string $updatedAt) => $this->postJson('/api/v1/sync', [
        'last_sync' => null,
        'changes' => ['ingredients' => [[
            'id' => $id,
            'name' => $name,
            'default_unit' => null,
            'category' => null,
            'created_at' => now()->subDay()->toISOString(),
            'updated_at' => $updatedAt,
            'deleted_at' => null,
        ]]],
    ])->assertSuccessful();

    $push('Current', now()->toISOString());
    $push('Stale', now()->subMinutes(10)->toISOString());        // older → ignored
    $this->assertDatabaseHas('ingredients', ['uuid' => $id, 'name' => 'Current']);

    $push('Latest', now()->addMinutes(10)->toISOString());        // newer → wins
    $this->assertDatabaseHas('ingredients', ['uuid' => $id, 'name' => 'Latest']);
});

it('propagates deletes as tombstones that fresh clients never receive', function () {
    $this->freezeTime();

    $ingredient = syncRow(['name' => 'Milk', 'default_unit' => 'l', 'category' => 'Dairy']);
    $first = $this->postJson('/api/v1/sync', [
        'last_sync' => null,
        'changes' => ['ingredients' => [$ingredient]],
    ])->assertSuccessful();
    $cursor = $first->json('server_time');

    $this->travel(1)->minutes();

    $deletedAt = now()->toISOString();
    $this->postJson('/api/v1/sync', [
        'last_sync' => $cursor,
        'changes' => ['ingredients' => [array_merge($ingredient, [
            'updated_at' => $deletedAt,
            'deleted_at' => $deletedAt,
        ])]],
    ])->assertSuccessful();

    // The row is kept, marked deleted.
    expect(Ingredient::firstWhere('uuid', $ingredient['id'])->deleted_at)->not->toBeNull();

    // A peer that synced before the delete pulls the tombstone.
    $peer = $this->postJson('/api/v1/sync', ['last_sync' => $cursor, 'changes' => []]);
    expect($peer->json('changes.ingredients.0.id'))->toBe($ingredient['id']);
    expect($peer->json('changes.ingredients.0.deleted_at'))->not->toBeNull();

    // A brand-new client (no cursor) only gets live rows — no tombstone.
    $fresh = $this->postJson('/api/v1/sync', ['last_sync' => null, 'changes' => []]);
    expect($fresh->json('changes.ingredients'))->toBeEmpty();
});

it('returns only rows changed since the cursor', function () {
    $this->freezeTime();

    $first = $this->postJson('/api/v1/sync', [
        'last_sync' => null,
        'changes' => ['ingredients' => [$older = syncRow(['name' => 'Older', 'default_unit' => null, 'category' => null])]],
    ])->assertSuccessful();
    $cursor = $first->json('server_time');

    $this->travel(1)->minutes();

    $second = $this->postJson('/api/v1/sync', [
        'last_sync' => $cursor,
        'changes' => ['ingredients' => [$newer = syncRow(['name' => 'Newer', 'default_unit' => null, 'category' => null])]],
    ])->assertSuccessful();

    $returnedIds = collect($second->json('changes.ingredients'))->pluck('id');
    expect($returnedIds)->toContain($newer['id'])
        ->and($returnedIds)->not->toContain($older['id']);
});

it('never leaks another household\'s rows', function () {
    [, $otherHousehold] = ownerWithHousehold();
    Ingredient::factory()->for($otherHousehold)->create();

    $pull = $this->postJson('/api/v1/sync', ['last_sync' => null, 'changes' => []])->assertSuccessful();

    expect($pull->json('changes.ingredients'))->toBeEmpty();
});

it('syncs dinner plans with entries, resolving the enum and date fields', function () {
    $dinner = syncRow(['name' => 'Tacos', 'default_servings' => 3, 'notes' => null]);
    $plan = syncRow(['name' => 'Week 23', 'start_date' => '2026-06-01', 'end_date' => '2026-06-07']);
    $entry = syncRow([
        'dinner_plan_id' => $plan['id'],
        'dinner_id' => $dinner['id'],
        'scheduled_date' => '2026-06-03',
        'servings' => 3,
        'meal_type' => 'dinner',
        'notes' => 'Friday',
    ]);

    $response = $this->postJson('/api/v1/sync', [
        'last_sync' => null,
        'changes' => [
            'dinners' => [$dinner],
            'dinner_plans' => [$plan],
            'plan_entries' => [$entry],
        ],
    ])->assertSuccessful();

    $response->assertJsonPath('changes.plan_entries.0.dinner_plan_id', $plan['id'])
        ->assertJsonPath('changes.plan_entries.0.dinner_id', $dinner['id'])
        ->assertJsonPath('changes.plan_entries.0.meal_type', 'dinner')
        ->assertJsonPath('changes.plan_entries.0.scheduled_date', '2026-06-03');

    $this->assertDatabaseHas('dinner_plan_entries', [
        'uuid' => $entry['id'],
        'meal_type' => 'dinner',
        'servings' => 3,
    ]);
});

it('syncs shopping lists with free-text and nullable foreign keys', function () {
    $list = syncRow(['name' => 'Saturday shop', 'dinner_plan_id' => null]);
    $freeText = syncRow([
        'shopping_list_id' => $list['id'],
        'ingredient_id' => null,
        'name' => 'Paper towels',
        'quantity' => 2,
        'unit' => 'rolls',
        'is_checked' => false,
    ]);

    $response = $this->postJson('/api/v1/sync', [
        'last_sync' => null,
        'changes' => [
            'shopping_lists' => [$list],
            'shopping_list_items' => [$freeText],
        ],
    ])->assertSuccessful();

    $response->assertJsonPath('changes.shopping_lists.0.dinner_plan_id', null)
        ->assertJsonPath('changes.shopping_list_items.0.ingredient_id', null)
        ->assertJsonPath('changes.shopping_list_items.0.name', 'Paper towels')
        ->assertJsonPath('changes.shopping_list_items.0.is_checked', false);

    $this->assertDatabaseHas('shopping_list_items', [
        'uuid' => $freeText['id'],
        'name' => 'Paper towels',
        'ingredient_id' => null,
    ]);
});

it('requires an active household', function () {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/v1/sync', ['last_sync' => null, 'changes' => []])->assertConflict();
});
