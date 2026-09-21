<?php

use App\Actions\Sync\ApplySyncBatch;
use App\Actions\Users\DeleteAccount;
use App\Enums\HouseholdRole;
use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('never queries native UUID columns with an invalid incoming identity', function () {
    DB::enableQueryLog();

    try {
        $valid = ingredientRow();
        sync(null, ['ingredients' => [$valid, array_replace(ingredientRow(), ['id' => 'invalid-uuid'])]])
            ->assertSuccessful()
            ->assertJsonPath('rejected.ingredients.0.code', 'invalid');

        $this->assertDatabaseHas('ingredients', ['uuid' => $valid['id']]);

        expect(collect(DB::getQueryLog())->pluck('bindings')->flatten()->all())
            ->not->toContain('invalid-uuid');
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

it('rejects live children referencing a tombstoned parent', function (string $deletedResource, bool $sameBatch) {
    $ingredient = ingredientRow();
    $dinner = dinnerRow();
    $item = syncRow(['dinner_id' => $dinner['id'], 'ingredient_id' => $ingredient['id'], 'quantity' => 1, 'unit' => 'g']);
    $cursor = sync(null, ['ingredients' => [$ingredient], 'dinners' => [$dinner]])->json('cursor');
    $deletedId = $deletedResource === 'ingredients' ? $ingredient['id'] : $dinner['id'];
    $changes = [$deletedResource => [tombstone($deletedId)]];

    if (! $sameBatch) {
        $cursor = sync($cursor, $changes)->assertSuccessful()->json('cursor');
        $changes = [];
    }

    $changes['dinner_items'] = [$item];
    sync($cursor, $changes)->assertSuccessful()
        ->assertJsonPath('rejected.dinner_items.0.code', 'unknown_parent');

    $this->assertDatabaseMissing('dinner_items', ['uuid' => $item['id']]);
})->with(['ingredients', 'dinners'])->with([true, false]);

it('allows children after their tombstoned parent is explicitly restored', function () {
    $ingredient = ingredientRow();
    $dinner = dinnerRow();
    $cursor = sync(null, ['ingredients' => [$ingredient], 'dinners' => [$dinner]])->json('cursor');
    $cursor = sync($cursor, ['dinners' => [tombstone($dinner['id'])]])->json('cursor');
    $this->travel(1)->seconds();
    $dinner['updated_at'] = now()->toISOString();
    $item = syncRow(['dinner_id' => $dinner['id'], 'ingredient_id' => $ingredient['id'], 'quantity' => 1, 'unit' => 'g']);

    sync($cursor, ['dinners' => [$dinner], 'dinner_items' => [$item]])->assertSuccessful()
        ->assertJsonPath('rejected', []);

    $this->assertDatabaseHas('dinner_items', ['uuid' => $item['id'], 'deleted_at' => null]);
});

it('refuses a malformed envelope, an unknown resource, and an oversized batch', function () {
    sync(null, ['ingredients' => 'nope'])->assertStatus(422);
    sync(null, ['cars' => []])->assertStatus(422);
    $this->postJson('/api/v1/sync', ['cursor' => 'yesterday', 'changes' => []])->assertStatus(422);

    $rows = array_map(fn () => ingredientRow((string) Str::random(8)), range(1, 1001));
    sync(null, ['ingredients' => $rows])->assertStatus(422);
});

it('keeps the newest edit when conflicting updates were made within the same second', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-21T12:00:01Z'));
    $ingredient = ingredientRow('Newest');
    $ingredient['updated_at'] = '2026-09-21T12:00:00.900000Z';
    $cursor = sync(null, ['ingredients' => [$ingredient]])->assertSuccessful()->json('cursor');
    $ingredient['name'] = 'Older';
    $ingredient['updated_at'] = '2026-09-21T12:00:00.100000Z';

    sync($cursor, ['ingredients' => [$ingredient]])->assertSuccessful()
        ->assertJsonPath('changes.ingredients.0.name', 'Newest')
        ->assertJsonPath('changes.ingredients.0.updated_at', '2026-09-21T12:00:00.900000Z');

    $this->assertDatabaseHas('ingredients', ['uuid' => $ingredient['id'], 'name' => 'Newest']);
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

it('answers an up-to-date empty poll without allocating a version', function () {
    $cursor = sync(null, ['ingredients' => [ingredientRow()]])->json('cursor');
    $before = $this->household->fresh()->sync_version;

    $poll = sync($cursor)->assertSuccessful();

    expect($poll->json('cursor'))->toBe($cursor)
        ->and($poll->json('changes'))->toBe(array_fill_keys(
            ['ingredients', 'dinners', 'dinner_items', 'dinner_plans', 'plan_entries', 'shopping_lists', 'shopping_list_items'],
            [],
        ))
        ->and($poll->json('rejected'))->toBe([])
        ->and($poll->json('remaps'))->toBe([])
        ->and($this->household->fresh()->sync_version)->toBe($before);
});

it('does not advance the household version for a pull that writes nothing', function () {
    $cursor = sync(null, ['ingredients' => [ingredientRow()]])->json('cursor');
    // A REST write the device has not seen yet: the pull must return it, but
    // pulling is not a write and must not mint a version of its own.
    $this->postJson('/api/v1/ingredients', ['name' => 'Flour', 'default_unit' => 'g'])->assertCreated();
    $version = $this->household->fresh()->sync_version;
    expect($version)->toBeGreaterThan($cursor);

    $pull = sync($cursor)->assertSuccessful();

    expect(collect($pull->json('changes.ingredients'))->pluck('name'))->toContain('Flour')
        ->and($pull->json('cursor'))->toBe($version)
        ->and($this->household->fresh()->sync_version)->toBe($version);
});

it('stamps a REST delete and its cascade with the version it commits', function () {
    $dinner = Dinner::factory()->for($this->household)->create();
    $item = DinnerItem::factory()->for($dinner, 'dinner')->create();
    $before = $this->household->fresh()->sync_version;

    $this->deleteJson('/api/v1/dinners/'.$dinner->id)->assertNoContent();

    $version = $this->household->fresh()->sync_version;
    expect($version)->toBe($before + 1)
        ->and(Dinner::withTrashed()->find($dinner->id)->sync_version)->toBe($version)
        ->and(DinnerItem::withTrashed()->find($item->id)->sync_version)->toBe($version);
});

it('shares one version across every row a REST request writes', function () {
    $ingredients = Ingredient::factory()->count(3)->for($this->household)->create();
    $before = $this->household->fresh()->sync_version;

    $response = $this->postJson('/api/v1/dinners', [
        'name' => 'Bolognese',
        'default_servings' => 4,
        'items' => $ingredients->map(fn (Ingredient $i) => ['ingredient_id' => $i->id, 'quantity' => 1, 'unit' => 'g'])->all(),
    ])->assertSuccessful();

    $version = $this->household->fresh()->sync_version;
    $dinner = Dinner::find($response->json('data.id'));
    expect($version)->toBe($before + 1)
        ->and($dinner->sync_version)->toBe($version)
        ->and($dinner->items()->pluck('sync_version')->unique()->all())->toBe([$version]);
});

it('rolls a failed REST write back with its version', function () {
    $before = $this->household->fresh()->sync_version;
    $foreign = Ingredient::factory()->create();

    $this->postJson('/api/v1/dinners', [
        'name' => 'X',
        'default_servings' => 2,
        'items' => [['ingredient_id' => $foreign->id, 'quantity' => 1, 'unit' => 'g']],
    ])->assertUnprocessable();

    expect(Dinner::count())->toBe(0)
        ->and($this->household->fresh()->sync_version)->toBe($before);
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

it('detaches shopping lists when their plan is deleted and syncs the remaining list', function () {
    $otherMember = User::factory()->create();
    $plan = DinnerPlan::factory()->for($this->household)->create();
    $list = ShoppingList::factory()->for($this->household)->for($plan)->create([
        'created_by_user_id' => $otherMember->id,
        'name' => 'Keep this list',
    ]);
    $item = ShoppingListItem::factory()->for($list)->create(['ingredient_id' => null, 'name' => 'Keep this item']);
    $cursor = sync(null)->json('cursor');

    sync($cursor, ['dinner_plans' => [tombstone($plan->uuid)]])->assertSuccessful()
        ->assertJsonPath('changes.shopping_lists.0.dinner_plan_id', null);
    $list->refresh();

    expect($list->dinner_plan_id)->toBeNull()
        ->and($list->created_by_user_id)->toBe($otherMember->id)
        ->and($list->name)->toBe('Keep this list')
        ->and($item->fresh()->name)->toBe('Keep this item')
        ->and($item->fresh()->trashed())->toBeFalse();

    sync(null)->assertSuccessful()->assertJsonPath('changes.shopping_lists.0.dinner_plan_id', null);
});

it('attributes mobile-created resources to the authenticated user and erases them on account deletion', function () {
    $otherMember = User::factory()->create(['current_household_id' => $this->household->id]);
    $this->household->members()->attach($otherMember, ['role' => HouseholdRole::Owner->value]);
    $dinner = dinnerRow('Private recipe');
    $plan = syncRow(['name' => 'Private plan', 'start_date' => null, 'end_date' => null]);
    $list = syncRow(['name' => 'Private list', 'dinner_plan_id' => $plan['id']]);
    $changes = [
        'dinners' => [$dinner + ['created_by_user_id' => $otherMember->id]],
        'dinner_plans' => [$plan + ['created_by_user_id' => $otherMember->id]],
        'shopping_lists' => [$list + ['created_by_user_id' => $otherMember->id]],
    ];
    sync(null, $changes)->assertSuccessful();

    $models = [Dinner::firstWhere('uuid', $dinner['id']), DinnerPlan::firstWhere('uuid', $plan['id']), ShoppingList::firstWhere('uuid', $list['id'])];
    foreach ($models as $model) {
        expect($model->created_by_user_id)->toBe($this->user->id);
    }

    Passport::actingAs($otherMember);
    $this->travel(1)->seconds();
    $dinner['name'] = 'Edited by another member';
    $dinner['updated_at'] = now()->toISOString();
    sync(null, ['dinners' => [$dinner + ['created_by_user_id' => $otherMember->id]]])->assertSuccessful();
    expect($models[0]->fresh()->created_by_user_id)->toBe($this->user->id);

    app(DeleteAccount::class)->handle($this->user);

    foreach ($models as $model) {
        $model->refresh();
        expect($model->trashed())->toBeTrue()
            ->and($model->name)->toBe('')
            ->and($model->created_by_user_id)->toBeNull();
    }
    $this->assertModelExists($this->household);
    $this->assertModelExists($otherMember);

    expect(fn () => app(ApplySyncBatch::class)->handle($this->household, $this->user, null, ['dinners' => [$dinner]]))
        ->toThrow(HttpException::class, 'You are no longer a member of this household.');
    expect($models[0]->fresh()->trashed())->toBeTrue();
});

it('merges concurrent recipe ingredient additions and redirects subsequent edits to the survivor', function () {
    $ingredient = ingredientRow('Rice');
    $dinner = dinnerRow('Rice');
    $first = syncRow(['dinner_id' => $dinner['id'], 'ingredient_id' => $ingredient['id'], 'quantity' => 100, 'unit' => 'g']);
    $initial = sync(null, ['ingredients' => [$ingredient], 'dinners' => [$dinner], 'dinner_items' => [$first]])->assertSuccessful();
    $this->travel(1)->seconds();
    $duplicate = syncRow(['dinner_id' => $dinner['id'], 'ingredient_id' => $ingredient['id'], 'quantity' => 200, 'unit' => ' G ']);
    $response = sync($initial->json('cursor'), ['dinner_items' => [$duplicate]])->assertSuccessful()
        ->assertJsonPath('rejected', [])->assertJsonPath('remaps.dinner_items.'.$duplicate['id'], $first['id']);
    expect(DinnerItem::query()->count())->toBe(1)
        ->and((float) DinnerItem::firstWhere('uuid', $first['id'])->quantity)->toBe(200.0);
    $this->assertSoftDeleted('dinner_items', ['uuid' => $duplicate['id']]);
    $this->travel(1)->seconds();
    $duplicate['updated_at'] = now()->toISOString();
    $duplicate['quantity'] = 300;
    $response = sync($response->json('cursor'), ['dinner_items' => [$duplicate]])->assertSuccessful()
        ->assertJsonPath('remaps.dinner_items.'.$duplicate['id'], $first['id']);
    expect(DinnerItem::query()->count())->toBe(1)
        ->and((float) DinnerItem::firstWhere('uuid', $first['id'])->quantity)->toBe(300.0);
    $this->travel(1)->seconds();
    sync($response->json('cursor'), ['dinner_items' => [tombstone($duplicate['id'])]])->assertSuccessful();
    expect(DinnerItem::query()->count())->toBe(0);
});

it('preserves different units while reconciling existing duplicate items on first sync', function () {
    $dinner = Dinner::factory()->for($this->household)->create();
    $ingredient = Ingredient::factory()->for($this->household)->create();
    $first = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit' => 'g']);
    $duplicate = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit' => 'g']);
    $cup = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 1, 'unit' => 'cup']);
    sync()->assertSuccessful()->assertJsonPath('remaps.dinner_items.'.$duplicate->uuid, $first->uuid);
    expect($dinner->items()->count())->toBe(2);
    $this->assertNotSoftDeleted($cup);
});

it('round trips generated shopping provenance through sync', function () {
    $list = syncRow(['name' => 'Week', 'dinner_plan_id' => null]);
    $item = syncRow(['shopping_list_id' => $list['id'], 'ingredient_id' => null, 'name' => 'Rice', 'quantity' => 100,
        'unit' => 'g', 'is_checked' => false, 'is_generated' => true]);
    sync(null, ['shopping_lists' => [$list], 'shopping_list_items' => [$item]])->assertSuccessful()
        ->assertJsonPath('changes.shopping_list_items.0.is_generated', true);
    $this->assertDatabaseHas('shopping_list_items', ['uuid' => $item['id'], 'is_generated' => true]);
});

it('reports rejected alias edits using the incoming identity without changing the survivor', function () {
    $dinner = Dinner::factory()->for($this->household)->create();
    $ingredient = Ingredient::factory()->for($this->household)->create();
    $first = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit' => 'g']);
    $duplicate = $dinner->items()->create(['ingredient_id' => $ingredient->id, 'quantity' => 100, 'unit' => 'g']);
    $initial = sync()->assertSuccessful();
    $this->travel(1)->seconds();
    sync($initial->json('cursor'), ['dinner_items' => [syncRow(['id' => $duplicate->uuid, 'dinner_id' => $dinner->uuid,
        'ingredient_id' => (string) Str::uuid(), 'quantity' => 200, 'unit' => 'g'])]])->assertSuccessful()
        ->assertJsonPath('rejected.dinner_items.0.id', $duplicate->uuid)
        ->assertJsonPath('rejected.dinner_items.0.code', 'unknown_parent')
        ->assertJsonPath('remaps.dinner_items.'.$duplicate->uuid, $first->uuid);
    expect((float) $first->fresh()->quantity)->toBe(100.0);
});
