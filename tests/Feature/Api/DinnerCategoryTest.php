<?php

use App\Actions\Users\DeleteAccount;
use App\Enums\HouseholdRole;
use App\Models\Dinner;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

it('creates and renames household categories without changing their dinner assignments', function () {
    $category = $this->postJson('/api/v1/dinner-categories', ['name' => ' Quick   meals '])
        ->assertCreated()->assertJsonPath('data.name', 'Quick meals')->json('data.id');
    $dinner = $this->postJson('/api/v1/dinners', ['name' => 'Soup', 'category' => $category])
        ->assertSuccessful()->assertJsonPath('data.category', $category)->json('data.id');
    $this->patchJson('/api/v1/dinner-categories/'.$category, ['name' => 'Weeknight'])->assertOk();
    $this->getJson('/api/v1/dinner-categories')->assertJsonPath('data.0.name', 'Weeknight');
    $this->getJson('/api/v1/dinners/'.$dinner)->assertJsonPath('data.category', $category);
    $this->deleteJson('/api/v1/dinner-categories/'.$category)->assertNoContent();
    $this->getJson('/api/v1/dinners/'.$dinner)->assertOk()->assertJsonPath('data.category', null)->assertJsonPath('data.name', 'Soup');
    $this->getJson('/api/v1/dinner-categories')->assertJsonCount(0, 'data');
});

it('scopes category visibility edits and assignments to the active household', function () {
    [, $other] = ownerWithHousehold();
    $category = $other->dinnerCategories()->create(['name' => 'Private']);
    $this->getJson('/api/v1/dinner-categories')->assertJsonCount(0, 'data');
    $this->patchJson('/api/v1/dinner-categories/'.$category->uuid, ['name' => 'Changed'])->assertNotFound();
    $this->deleteJson('/api/v1/dinner-categories/'.$category->uuid)->assertNotFound();
    $this->postJson('/api/v1/dinners', ['name' => 'Soup', 'category' => $category->uuid])->assertUnprocessable();
});

it('answers 404 rather than a database error for a category id that is not a uuid', function () {
    $this->patchJson('/api/v1/dinner-categories/produce', ['name' => 'Changed'])->assertNotFound();
    $this->deleteJson('/api/v1/dinner-categories/produce')->assertNotFound();
});

it('validates custom category names', function (mixed $name) {
    $this->postJson('/api/v1/dinner-categories', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
})->with(['', '   ', str_repeat('x', 81), [['not a name']]]);

it('erases category contributions and clears references when its creator deletes their account', function () {
    $member = User::factory()->create();
    $this->household->members()->attach($member, ['role' => HouseholdRole::Member->value]);
    $category = $this->household->dinnerCategories()->create(['name' => 'Personal label']);
    $dinner = Dinner::factory()->for($this->household)->create(['created_by_user_id' => $member->id, 'category' => $category->uuid]);
    // Keep the dinner owned/authored entirely by the remaining household member.
    $dinner->forceFill(['content_authors' => null])->withoutContentAttribution()->save();
    app(DeleteAccount::class)->handle($this->user);
    expect($category->fresh())->name->toBe('Category')->deleted_at->not->toBeNull();
    expect($dinner->fresh())->category->toBeNull()->deleted_at->toBeNull();
});
