<?php

use App\Actions\ShoppingLists\GenerateShoppingListFromPlan;
use App\Actions\Sync\AllocateSyncVersion;
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
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('attributes REST ingredient and nested writes and anonymises them without deleting the households data', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create(['current_household_id' => $household->id]);
    $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    $plan = DinnerPlan::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    $list = ShoppingList::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    Passport::actingAs($member);

    $ingredientId = $this->postJson('/api/v1/ingredients', ['name' => 'Private ingredient'])
        ->assertSuccessful()->json('data.id');
    $this->patchJson("/api/v1/dinners/{$dinner->id}", [
        'name' => $dinner->name, 'default_servings' => $dinner->default_servings,
        'notes' => 'My private medical notes',
        'items' => [['ingredient_id' => $ingredientId, 'quantity' => 1, 'unit' => 'My private unit']],
    ])->assertSuccessful();
    $this->postJson("/api/v1/dinner-plans/{$plan->id}/entries", [
        'dinner_id' => $dinner->id, 'scheduled_date' => '2026-09-21',
        'servings' => 2, 'meal_type' => 'dinner', 'notes' => 'My private event',
    ])->assertSuccessful();
    $this->postJson("/api/v1/shopping-lists/{$list->id}/items", [
        'name' => 'My private item',
    ])->assertSuccessful();

    $ingredient = Ingredient::findOrFail($ingredientId);
    $item = $dinner->items()->firstOrFail();
    $entry = $plan->entries()->firstOrFail();
    $listItem = $list->items()->firstOrFail();
    foreach ([$ingredient, $item, $entry, $listItem] as $resource) {
        expect($resource->created_by_user_id)->toBe($member->id);
    }
    expect($dinner->fresh()->content_authors['user_'.$member->id])->toBe(['notes']);
    $household->members()->detach($member);
    app(AllocateSyncVersion::class)->forget();
    $cursor = $household->fresh()->sync_version;
    app(DeleteAccount::class)->handle($member);

    foreach ([$ingredient, $item, $entry, $listItem, $dinner] as $resource) {
        expect($resource->fresh()->trashed())->toBeFalse()
            ->and($resource->fresh()->created_by_user_id)->not->toBe($member->id)
            ->and($resource->fresh()->content_authors ?? [])->not->toHaveKey('user_'.$member->id)
            ->and($resource->fresh()->sync_version)->toBeGreaterThan($cursor);
    }
    // Shared names and amounts stay; only the personal notes go.
    expect($ingredient->fresh()->name)->toBe('Private ingredient')
        ->and($item->fresh()->ingredient_id)->toBe($ingredient->id)
        ->and($item->fresh()->unit)->toBe('My private unit')
        ->and($listItem->fresh()->name)->toBe('My private item')
        ->and($entry->fresh()->notes)->toBeNull()
        ->and($entry->fresh()->erasure_version)->toBeGreaterThan(0)
        ->and($ingredient->fresh()->erasure_version)->toBe(0)
        ->and($dinner->fresh()->name)->toBe($dinner->name)
        ->and($dinner->fresh()->notes)->toBeNull()
        ->and($plan->fresh()->trashed())->toBeFalse()
        ->and($list->fresh()->trashed())->toBeFalse();
});

it('keeps earlier text contributors when another member edits the same field', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $owner->id, 'notes' => null]);
    $dinner->attributeContentTo($member->id)->fill(['notes' => 'Private allergy'])->save();
    $dinner->attributeContentTo($owner->id)->fill(['notes' => 'Private allergy; cook for ten minutes'])->save();
    $dinner->delete();

    app(DeleteAccount::class)->handle($member);

    expect($dinner->fresh()->notes)->toBeNull()
        ->and($dinner->fresh()->trashed())->toBeTrue()
        ->and($dinner->fresh()->content_authors)->toBeNull();
});

it('prevents stale and future dated offline payloads from restoring erased content while allowing informed edits', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $owner->id, 'notes' => null]);
    $dinner->attributeContentTo($member->id)->fill(['notes' => 'Private allergy'])->save();
    app(AllocateSyncVersion::class)->forget();
    $oldCursor = $household->fresh()->sync_version;
    app(DeleteAccount::class)->handle($member);
    $this->travel(1)->seconds();
    $row = ['id' => $dinner->uuid, 'name' => $dinner->name, 'default_servings' => $dinner->default_servings,
        'notes' => 'Private allergy', 'updated_at' => now()->addDay()->toISOString()];

    $action = app(ApplySyncBatch::class);
    $response = $action->handle($household->fresh(), $owner, $oldCursor, ['dinners' => [$row]]);
    expect($response['changes']['dinners'][0]['notes'])->toBeNull()
        ->and($response['changes']['dinners'][0]['erasure_version'])->toBeGreaterThan(0)
        ->and($response['changes']['dinners'][0])->not->toHaveKey('content_authors');
    $newCursor = $response['cursor'];
    $action->handle($household->fresh(), $owner, $newCursor, ['dinners' => [$row]]);
    expect($dinner->fresh()->notes)->toBeNull();

    $row['erasure_version'] = $dinner->fresh()->erasure_version;
    $row['notes'] = 'New recipe instructions';
    $action->handle($household->fresh(), $owner, $newCursor, ['dinners' => [$row]]);
    expect($dinner->fresh()->notes)->toBe('New recipe instructions');
});

it('keeps a leaving members ingredient for the recipes and shopping lists that use it', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);
    $ingredient = Ingredient::factory()->for($household)->make(['name' => 'Milk', 'created_by_user_id' => $member->id]);
    $ingredient->attributeContentTo($member->id)->save();
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    $item = DinnerItem::factory()->for($dinner)->for($ingredient)->create(['quantity' => 2, 'unit' => 'dl']);
    $list = ShoppingList::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    $listItem = ShoppingListItem::factory()->for($list)->for($ingredient)->create();
    app(DeleteAccount::class)->handle($member);
    app(AllocateSyncVersion::class)->forget();
    $this->travel(1)->seconds();

    expect($ingredient->fresh())->name->toBe('Milk')->created_by_user_id->toBeNull()->content_authors->toBeNull()
        ->and($ingredient->fresh()->trashed())->toBeFalse()
        ->and($item->fresh())->ingredient_id->toBe($ingredient->id)->unit->toBe('dl')
        ->and($listItem->fresh())->ingredient_id->toBe($ingredient->id)
        ->and($listItem->fresh()->trashed())->toBeFalse();

    // Nothing was erased, so offline edits from the others still apply.
    app(ApplySyncBatch::class)->handle($household->fresh(), $owner, null, [
        'ingredients' => [['id' => $ingredient->uuid, 'name' => 'Oat milk', 'updated_at' => now()->toISOString()]],
    ]);
    expect($ingredient->fresh()->name)->toBe('Oat milk');
});

it('attributes new sync children to the authenticated writer and ignores spoofed provenance', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);
    $list = ShoppingList::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    $uuid = (string) Str::uuid();
    app(ApplySyncBatch::class)->handle($household->fresh(), $member, null, [
        'shopping_list_items' => [['id' => $uuid, 'shopping_list_id' => $list->uuid, 'name' => 'Private shopping',
            'created_by_user_id' => $owner->id, 'content_authors' => ['user_'.$owner->id => ['name']], 'erasure_version' => 999]],
    ]);
    $item = ShoppingListItem::where('uuid', $uuid)->firstOrFail();
    expect($item->created_by_user_id)->toBe($member->id)
        ->and($item->content_authors['user_'.$member->id])->toContain('name')
        ->and($item->content_authors)->not->toHaveKey('user_'.$owner->id)
        ->and($item->erasure_version)->toBe(0);
    app(DeleteAccount::class)->handle($member);
    expect($item->fresh())->name->toBe('Private shopping')->created_by_user_id->toBeNull()->content_authors->toBeNull()
        ->and($item->fresh()->trashed())->toBeFalse()
        ->and($list->fresh()->trashed())->toBeFalse();
});

it('keeps ingredient text previously copied into legacy detached shopping items', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $ingredient = Ingredient::factory()->for($household)->make(['name' => 'Private ingredient']);
    $ingredient->attributeContentTo($member->id)->save();
    $list = ShoppingList::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    $item = ShoppingListItem::factory()->for($list)->for($ingredient)->create(['name' => null]);
    // Older versions detached shopping rows and copied the ingredient's name.
    $item->name = $ingredient->name;
    $item->ingredient_id = null;
    $item->inheritContentAuthors($ingredient, ['name' => 'name'])->save();
    $ingredient->delete();
    expect($item->fresh()->name)->toBe('Private ingredient');

    app(DeleteAccount::class)->handle($member);

    expect($item->fresh()->name)->toBe('Private ingredient')
        ->and($item->fresh()->content_authors)->toBeNull()
        ->and($item->fresh()->trashed())->toBeFalse();
});

it('preserves provenance when generating shopping lists and keeps shared names when a contributor leaves', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $household->attributeContentTo($member->id)->fill(['name' => 'Private household'])->save();
    $ingredient = Ingredient::factory()->for($household)->create();
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    $recipeItem = DinnerItem::factory()->for($dinner)->for($ingredient)->create(['unit' => 'g']);
    $recipeItem->attributeContentTo($member->id)->fill(['unit' => 'Private unit'])->save();
    $plan = DinnerPlan::factory()->for($household)->create(['created_by_user_id' => $owner->id]);
    $plan->attributeContentTo($member->id)->fill(['name' => 'Private plan'])->save();
    DinnerPlanEntry::factory()->for($plan)->for($dinner)->create();
    $list = app(GenerateShoppingListFromPlan::class)->handle($plan, $owner);
    $item = $list->items()->firstOrFail();

    app(DeleteAccount::class)->handle($member);

    expect($list->content_authors)->toHaveKey('user_'.$member->id)
        ->and($item->content_authors)->toHaveKey('user_'.$member->id);
    expect($household->fresh())->name->toBe('Private household')->content_authors->toBeNull()
        ->and($list->fresh())->name->toBe($list->name)->content_authors->not->toHaveKey('user_'.$member->id)
        ->and($item->fresh())->unit->toBe('private unit')->content_authors->not->toHaveKey('user_'.$member->id)
        ->and($list->fresh()->trashed())->toBeFalse()
        ->and($item->fresh()->trashed())->toBeFalse();
});

it('merges provenance when an already loaded model is edited after another member', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $owner->id, 'notes' => null]);
    $stale = $dinner->fresh();
    $dinner->attributeContentTo($member->id)->fill(['notes' => 'Private allergy'])->save();
    $stale->attributeContentTo($owner->id)->fill(['notes' => 'Private allergy; cook slowly'])->save();

    app(DeleteAccount::class)->handle($member);

    expect($dinner->fresh()->notes)->toBeNull();
});

it('rejects writes from a model loaded before account erasure', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $dinner = Dinner::factory()->for($household)->create(['created_by_user_id' => $owner->id, 'notes' => null]);
    $dinner->attributeContentTo($member->id)->fill(['notes' => 'Private allergy'])->save();
    $stale = $dinner->fresh();
    app(AllocateSyncVersion::class)->forget();
    app(DeleteAccount::class)->handle($member);

    expect(fn () => $stale->attributeContentTo($owner->id)->fill(['notes' => 'Private allergy; edit'])->save())
        ->toThrow(HttpException::class);
    expect($dinner->fresh()->notes)->toBeNull();
});

it('lets the remaining members keep editing a household name a leaving member chose', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $household->attributeContentTo($member->id)->fill(['name' => 'Shared name'])->save();
    app(DeleteAccount::class)->handle($member);

    expect($household->fresh())->name->toBe('Shared name')->content_authors->toBeNull();

    $household->fresh()->attributeContentTo($owner->id)->fill(['name' => 'Renamed'])->save();
    expect($household->fresh())->name->toBe('Renamed')->content_authors->toBe(['user_'.$owner->id => ['name']]);
});
