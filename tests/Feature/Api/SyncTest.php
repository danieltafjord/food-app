<?php

use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

/** @return array<string, mixed> */
function ingredientRow(string $name = 'Beef'): array
{
    return syncRow(['name' => $name, 'default_unit' => 'g', 'category' => 'meat']);
}

/** @return array<string, mixed> */
function dinnerRow(string $name = 'Bolognese'): array
{
    return syncRow(['name' => $name, 'default_servings' => 4, 'notes' => null]);
}

/**
 * @param  array<string, array<int, array<string, mixed>>>  $changes
 */
function sync(?int $cursor = null, array $changes = [], ?int $householdId = null): TestResponse
{
    return test()->postJson('/api/v1/sync', [
        'cursor' => $cursor,
        'household_id' => $householdId,
        'changes' => $changes,
    ]);
}

/**
 * A tombstone in the shape the mobile client sends: identity + timestamps only.
 *
 * @return array<string, mixed>
 */
function tombstone(string $uuid, ?string $at = null): array
{
    $at ??= now()->toISOString();

    return ['id' => $uuid, 'updated_at' => $at, 'deleted_at' => $at];
}

it('pushes a full batch, resolving foreign keys and scoping to the household', function () {
    $ingredient = ingredientRow();
    $dinner = dinnerRow();
    $item = syncRow([
        'dinner_id' => $dinner['id'],
        'ingredient_id' => $ingredient['id'],
        'quantity' => 500,
        'unit' => 'g',
    ]);

    $response = sync(null, [
        'ingredients' => [$ingredient],
        'dinners' => [$dinner],
        'dinner_items' => [$item],
    ]);

    $response->assertSuccessful()
        ->assertJsonStructure(['cursor', 'household_id', 'changes' => ['ingredients', 'dinners', 'dinner_items'], 'rejected', 'remaps'])
        ->assertJsonPath('household_id', $this->household->id)
        ->assertJsonPath('rejected', [])
        ->assertJsonPath('changes.dinner_items.0.dinner_id', $dinner['id'])
        ->assertJsonPath('changes.dinner_items.0.ingredient_id', $ingredient['id']);

    $this->assertDatabaseHas('ingredients', [
        'uuid' => $ingredient['id'],
        'household_id' => $this->household->id,
        'name' => 'Beef',
    ]);

    $dinnerModel = Dinner::firstWhere('uuid', $dinner['id']);
    $ingredientModel = Ingredient::firstWhere('uuid', $ingredient['id']);

    $this->assertDatabaseHas('dinner_items', [
        'uuid' => $item['id'],
        'dinner_id' => $dinnerModel->id,
        'ingredient_id' => $ingredientModel->id,
    ]);

    // Every row in the batch shares the version the response hands back as the cursor.
    expect($dinnerModel->sync_version)->toBe($response->json('cursor'))
        ->and($this->household->fresh()->sync_version)->toBe($response->json('cursor'));
});

it('rejects a row whose foreign key references nothing without failing the batch', function () {
    $good = ingredientRow();
    $orphan = syncRow([
        'dinner_id' => (string) Str::uuid(),
        'ingredient_id' => (string) Str::uuid(),
        'quantity' => 1,
        'unit' => 'g',
    ]);

    $response = sync(null, ['ingredients' => [$good], 'dinner_items' => [$orphan]])->assertSuccessful();

    $response->assertJsonPath('rejected.dinner_items.0.id', $orphan['id'])
        ->assertJsonPath('rejected.dinner_items.0.code', 'unknown_parent');
    $this->assertDatabaseHas('ingredients', ['uuid' => $good['id']]);
    $this->assertDatabaseMissing('dinner_items', ['uuid' => $orphan['id']]);
});

it('rejects invalid rows individually instead of returning 500', function () {
    $dinner = dinnerRow();
    $plan = syncRow(['name' => 'Week', 'start_date' => '2026-06-01', 'end_date' => '2026-06-07']);
    $badMeal = syncRow([
        'dinner_plan_id' => $plan['id'], 'dinner_id' => $dinner['id'],
        'scheduled_date' => '2026-06-03', 'servings' => 2, 'meal_type' => 'brunch', 'notes' => null,
    ]);
    $badDate = syncRow(['name' => 'Bad', 'default_servings' => 2, 'notes' => null, 'updated_at' => 'not-a-date']);
    $hugeServings = syncRow(['name' => 'Huge', 'default_servings' => 70000, 'notes' => null]);
    $notUuid = syncRow(['id' => 'abc', 'name' => 'x', 'default_unit' => null, 'category' => null]);

    $response = sync(null, [
        'ingredients' => [$notUuid],
        'dinners' => [$dinner, $badDate, $hugeServings],
        'dinner_plans' => [$plan],
        'plan_entries' => [$badMeal],
    ])->assertSuccessful();

    expect(collect($response->json('rejected.dinners'))->pluck('id')->all())->toBe([$badDate['id'], $hugeServings['id']])
        ->and($response->json('rejected.plan_entries.0.id'))->toBe($badMeal['id'])
        ->and($response->json('rejected.ingredients.0.id'))->toBe('abc');
    $this->assertDatabaseHas('dinners', ['uuid' => $dinner['id']]);
    $this->assertDatabaseHas('dinner_plans', ['uuid' => $plan['id']]);
});

it('refuses a malformed envelope, an unknown resource, and an oversized batch', function () {
    sync(null, ['ingredients' => 'nope'])->assertStatus(422);
    sync(null, ['cars' => []])->assertStatus(422);
    $this->postJson('/api/v1/sync', ['cursor' => 'yesterday', 'changes' => []])->assertStatus(422);

    $rows = array_map(fn () => ingredientRow((string) Str::random(8)), range(1, 1001));
    sync(null, ['ingredients' => $rows])->assertStatus(422);
});

it('keeps the newest version under last-write-wins and clamps future clocks', function () {
    $this->freezeTime();
    $id = (string) Str::uuid();

    $push = fn (string $name, string $updatedAt) => sync(null, ['ingredients' => [[
        'id' => $id,
        'name' => $name,
        'default_unit' => null,
        'category' => null,
        'created_at' => now()->subDay()->toISOString(),
        'updated_at' => $updatedAt,
        'deleted_at' => null,
    ]]])->assertSuccessful();

    $push('Current', now()->toISOString());
    $push('Stale', now()->subMinutes(10)->toISOString());
    $this->assertDatabaseHas('ingredients', ['uuid' => $id, 'name' => 'Current']);

    // A device with its clock a year ahead cannot lock the row: its timestamp
    // is clamped to the server clock, so a normal edit a minute later still wins.
    $push('Future', now()->addYear()->toISOString());
    expect(Ingredient::firstWhere('uuid', $id)->updated_at->timestamp)->toBe(now()->timestamp);

    $this->travel(1)->minutes();
    $push('Later', now()->toISOString());
    $this->assertDatabaseHas('ingredients', ['uuid' => $id, 'name' => 'Later']);
});

it('sends the server copy back when the client loses a conflict', function () {
    $ingredient = ingredientRow('Server');
    $cursor = sync(null, ['ingredients' => [$ingredient]])->json('cursor');

    $stale = array_merge($ingredient, ['name' => 'Stale', 'updated_at' => now()->subHour()->toISOString()]);
    $response = sync($cursor, ['ingredients' => [$stale]])->assertSuccessful();

    // Nothing above the cursor changed, yet the losing row comes back so the
    // client converges instead of keeping its rejected edit forever.
    expect($response->json('changes.ingredients.0.id'))->toBe($ingredient['id'])
        ->and($response->json('changes.ingredients.0.name'))->toBe('Server');
});

it('stores client timestamps as UTC whatever offset they carry', function () {
    $ingredient = array_merge(ingredientRow(), ['updated_at' => '2026-09-10T10:00:00+02:00']);
    sync(null, ['ingredients' => [$ingredient]])->assertSuccessful();

    expect(Ingredient::firstWhere('uuid', $ingredient['id'])->updated_at->toISOString())
        ->toBe('2026-09-10T08:00:00.000000Z');
});

it('applies a bare child tombstone and never re-sends children of a deleted parent to fresh clients', function () {
    $ingredient = ingredientRow();
    $dinner = dinnerRow();
    $item = syncRow(['dinner_id' => $dinner['id'], 'ingredient_id' => $ingredient['id'], 'quantity' => 1, 'unit' => 'g']);
    $cursor = sync(null, ['ingredients' => [$ingredient], 'dinners' => [$dinner], 'dinner_items' => [$item]])->json('cursor');

    // The client deletes as {id, updated_at, deleted_at} — no foreign keys.
    $response = sync($cursor, ['dinner_items' => [tombstone($item['id'])]])->assertSuccessful();
    expect($response->json('rejected'))->toBe([]);
    expect(DinnerItem::withTrashed()->firstWhere('uuid', $item['id'])->trashed())->toBeTrue();

    // Deleting the dinner tombstones the dinner and (server-side) its remaining children.
    $item2 = syncRow(['dinner_id' => $dinner['id'], 'ingredient_id' => $ingredient['id'], 'quantity' => 2, 'unit' => 'g']);
    $cursor = sync($cursor, ['dinner_items' => [$item2]])->json('cursor');
    sync($cursor, ['dinners' => [tombstone($dinner['id'])]])->assertSuccessful();
    expect(DinnerItem::withTrashed()->firstWhere('uuid', $item2['id'])->trashed())->toBeTrue();

    // A peer with a cursor pulls both tombstones; a fresh client gets neither row.
    $peer = sync($cursor)->assertSuccessful();
    expect(collect($peer->json('changes.dinners'))->firstWhere('id', $dinner['id'])['deleted_at'])->not->toBeNull()
        ->and(collect($peer->json('changes.dinner_items'))->firstWhere('id', $item2['id'])['deleted_at'])->not->toBeNull();

    $fresh = sync(null)->assertSuccessful();
    expect($fresh->json('changes.dinners'))->toBeEmpty()
        ->and($fresh->json('changes.dinner_items'))->toBeEmpty();
});

it('ignores a tombstone for a row the server never saw', function () {
    $response = sync(null, ['dinners' => [tombstone((string) Str::uuid())]])->assertSuccessful();

    expect($response->json('rejected'))->toBe([]);
    expect(Dinner::withTrashed()->count())->toBe(0);
});

it('restores a tombstoned row when a newer live version arrives', function () {
    $this->freezeTime();
    $dinner = dinnerRow();
    $cursor = sync(null, ['dinners' => [$dinner]])->json('cursor');
    sync($cursor, ['dinners' => [tombstone($dinner['id'])]])->assertSuccessful();

    $this->travel(1)->minutes();
    sync($cursor, ['dinners' => [array_merge($dinner, ['name' => 'Back', 'updated_at' => now()->toISOString()])]])
        ->assertSuccessful();

    $model = Dinner::withTrashed()->firstWhere('uuid', $dinner['id']);
    expect($model->trashed())->toBeFalse()->and($model->name)->toBe('Back');
});

it('returns only rows above the cursor, even when written in the same second', function () {
    $this->freezeTime();

    $older = ingredientRow('Older');
    $cursor = sync(null, ['ingredients' => [$older]])->json('cursor');

    // Same frozen second: a timestamp cursor would miss this; the version cursor cannot.
    $newer = ingredientRow('Newer');
    $second = sync($cursor, ['ingredients' => [$newer]])->assertSuccessful();

    $returnedIds = collect($second->json('changes.ingredients'))->pluck('id');
    expect($returnedIds)->toContain($newer['id'])
        ->and($returnedIds)->not->toContain($older['id'])
        ->and($second->json('cursor'))->toBeGreaterThan($cursor);
});

it('never leaks, overwrites, or parents onto another household\'s rows', function () {
    [, $otherHousehold] = ownerWithHousehold();
    $foreignIngredient = Ingredient::factory()->for($otherHousehold)->create(['name' => 'Theirs']);
    $foreignDinner = Dinner::factory()->for($otherHousehold)->create();
    $foreignItem = DinnerItem::factory()->for($foreignDinner, 'dinner')->create();

    $pull = sync(null)->assertSuccessful();
    expect($pull->json('changes.ingredients'))->toBeEmpty();

    $mine = ingredientRow('Mine');
    $response = sync(null, [
        'ingredients' => [$mine, array_merge(ingredientRow('Hijack'), ['id' => $foreignIngredient->uuid])],
        // A child hung on the foreign dinner: the parent uuid is unknown here.
        'dinner_items' => [
            syncRow(['dinner_id' => $foreignDinner->uuid, 'ingredient_id' => $mine['id'], 'quantity' => 1, 'unit' => null]),
            // A foreign child uuid with a newer timestamp must not be re-parented or deleted.
            tombstone($foreignItem->uuid),
        ],
    ])->assertSuccessful();

    expect($response->json('rejected.ingredients.0.code'))->toBe('unknown_id')
        ->and($response->json('rejected.dinner_items.0.code'))->toBe('unknown_parent');
    expect($foreignIngredient->fresh()->name)->toBe('Theirs')
        ->and(DinnerItem::withTrashed()->find($foreignItem->id)->trashed())->toBeFalse();
    expect(collect($response->json('changes.ingredients'))->pluck('id'))->not->toContain($foreignIngredient->uuid);
});

it('merges an ingredient created on two devices under the same name', function () {
    $first = ingredientRow('Melk');
    $cursor = sync(null, ['ingredients' => [$first]])->json('cursor');

    $duplicate = ingredientRow('melk');
    $item = syncRow(['dinner_id' => ($dinner = dinnerRow())['id'], 'ingredient_id' => $duplicate['id'], 'quantity' => 1, 'unit' => 'l']);
    $response = sync($cursor, ['ingredients' => [$duplicate], 'dinners' => [$dinner], 'dinner_items' => [$item]])
        ->assertSuccessful();

    $response->assertJsonPath("remaps.ingredients.{$duplicate['id']}", $first['id'])
        ->assertJsonPath('rejected', []);
    expect(Ingredient::where('household_id', $this->household->id)->count())->toBe(1);

    // The child in the same batch resolved onto the surviving ingredient.
    expect($response->json('changes.dinner_items.0.ingredient_id'))->toBe($first['id']);
});

it('refuses a device bound to a different household', function () {
    [, $other] = ownerWithHousehold();

    sync(null, [], $other->id)->assertConflict()->assertJsonPath('code', 'household_mismatch')
        ->assertJsonPath('household_id', $this->household->id);
    sync(null, [], $this->household->id)->assertSuccessful();
});

it('syncs dinner plans with entries, resolving the enum and date fields', function () {
    $dinner = dinnerRow('Tacos');
    $plan = syncRow(['name' => 'Week 23', 'start_date' => '2026-06-01', 'end_date' => '2026-06-07']);
    $entry = syncRow([
        'dinner_plan_id' => $plan['id'],
        'dinner_id' => $dinner['id'],
        'scheduled_date' => '2026-06-03',
        'servings' => 3,
        'meal_type' => 'dinner',
        'notes' => 'Friday',
    ]);

    $response = sync(null, ['dinners' => [$dinner], 'dinner_plans' => [$plan], 'plan_entries' => [$entry]])
        ->assertSuccessful();

    $response->assertJsonPath('changes.plan_entries.0.dinner_plan_id', $plan['id'])
        ->assertJsonPath('changes.plan_entries.0.dinner_id', $dinner['id'])
        ->assertJsonPath('changes.plan_entries.0.meal_type', 'dinner')
        ->assertJsonPath('changes.plan_entries.0.scheduled_date', '2026-06-03');

    $this->assertDatabaseHas('dinner_plan_entries', ['uuid' => $entry['id'], 'meal_type' => 'dinner', 'servings' => 3]);
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

    $response = sync(null, ['shopping_lists' => [$list], 'shopping_list_items' => [$freeText]])->assertSuccessful();

    $response->assertJsonPath('changes.shopping_lists.0.dinner_plan_id', null)
        ->assertJsonPath('changes.shopping_list_items.0.ingredient_id', null)
        ->assertJsonPath('changes.shopping_list_items.0.name', 'Paper towels')
        ->assertJsonPath('changes.shopping_list_items.0.is_checked', false);
});

it('exposes REST writes and deletes to a client that already has a cursor', function () {
    $cursor = sync(null)->json('cursor');

    // Created through the REST API (or the web), not through sync.
    $viaRest = $this->postJson('/api/v1/ingredients', ['name' => 'Flour', 'default_unit' => 'g'])->assertCreated();
    $pull = sync($cursor)->assertSuccessful();
    expect(collect($pull->json('changes.ingredients'))->pluck('name'))->toContain('Flour');
    $cursor = $pull->json('cursor');

    // Deleted through REST: the phone gets a tombstone rather than keeping the row.
    $this->deleteJson('/api/v1/ingredients/'.$viaRest->json('data.id'))->assertNoContent();
    $pull = sync($cursor)->assertSuccessful();
    expect($pull->json('changes.ingredients.0.name'))->toBe('Flour')
        ->and($pull->json('changes.ingredients.0.deleted_at'))->not->toBeNull();
});

it('tombstones a REST-deleted dinner\'s items so peers do not keep them', function () {
    $dinner = Dinner::factory()->for($this->household)->create();
    $item = DinnerItem::factory()->for($dinner, 'dinner')->create();
    $plan = DinnerPlan::factory()->for($this->household)->create();
    $entry = DinnerPlanEntry::factory()->for($plan, 'dinnerPlan')->for($dinner, 'dinner')->create();
    $cursor = sync(null)->json('cursor');

    $this->deleteJson('/api/v1/dinners/'.$dinner->id)->assertNoContent();

    $pull = sync($cursor)->assertSuccessful();
    expect(collect($pull->json('changes.dinner_items'))->firstWhere('id', $item->uuid)['deleted_at'])->not->toBeNull()
        ->and(collect($pull->json('changes.plan_entries'))->firstWhere('id', $entry->uuid)['deleted_at'])->not->toBeNull();
    expect(DinnerItem::withTrashed()->find($item->id)->trashed())->toBeTrue();
});

it('keeps a deleted ingredient on shopping lists as free text', function () {
    $ingredient = Ingredient::factory()->for($this->household)->create(['name' => 'Eggs']);
    $list = ShoppingList::factory()->for($this->household)->create();
    $listItem = ShoppingListItem::factory()->for($list, 'shoppingList')->create(['ingredient_id' => $ingredient->id, 'name' => null]);
    $cursor = sync(null)->json('cursor');

    // Deleting via sync (REST refuses while referenced).
    sync($cursor, ['ingredients' => [tombstone($ingredient->uuid)]])->assertSuccessful();

    expect($listItem->fresh())->ingredient_id->toBeNull()->name->toBe('Eggs');
});

it('requires an active household', function () {
    Passport::actingAs(User::factory()->create());

    sync()->assertConflict();
});
