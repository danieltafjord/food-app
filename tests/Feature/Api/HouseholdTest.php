<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Laravel\Passport\Passport;

it('lists the households a user belongs to with their role', function () {
    [$owner, $household] = ownerWithHousehold();
    Passport::actingAs($owner);

    $this->getJson('/api/v1/households')
        ->assertSuccessful()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $household->id)
        ->assertJsonPath('data.0.role', 'owner');
});

it('creates a household and makes the creator the owner', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/v1/households', ['name' => 'Casa'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Casa');

    $household = Household::firstWhere('name', 'Casa');

    expect($household->isOwnedBy($user))->toBeTrue()
        ->and($user->fresh()->current_household_id)->toBe($household->id);
});

it('validates the household name', function () {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/v1/households', ['name' => ''])->assertUnprocessable();
});

it('forbids non-members from viewing a household', function () {
    [, $household] = ownerWithHousehold();
    Passport::actingAs(User::factory()->create());

    $this->getJson("/api/v1/households/{$household->id}")->assertForbidden();
});

it('lets only owners rename a household', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);

    Passport::actingAs($member);
    $this->patchJson("/api/v1/households/{$household->id}", ['name' => 'New'])->assertForbidden();

    Passport::actingAs($owner);
    $this->patchJson("/api/v1/households/{$household->id}", ['name' => 'New'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'New');
});

it('defaults a new household to two servings', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $this->postJson('/api/v1/households', ['name' => 'Casa'])
        ->assertSuccessful()
        ->assertJsonPath('data.default_servings', 2);
});

it('creates a household with a chosen default servings', function () {
    Passport::actingAs(User::factory()->create());

    $this->postJson('/api/v1/households', ['name' => 'Casa', 'default_servings' => 6])
        ->assertSuccessful()
        ->assertJsonPath('data.default_servings', 6);

    expect(Household::firstWhere('name', 'Casa')->default_servings)->toBe(6);
});

it('lets owners update the household default servings', function () {
    [$owner, $household] = ownerWithHousehold();
    Passport::actingAs($owner);

    $this->patchJson("/api/v1/households/{$household->id}", [
        'name' => $household->name,
        'default_servings' => 5,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.default_servings', 5);

    expect($household->fresh()->default_servings)->toBe(5);
});

it('leaves default servings untouched on a name-only update', function () {
    [$owner, $household] = ownerWithHousehold();
    $household->update(['default_servings' => 6]);
    Passport::actingAs($owner);

    $this->patchJson("/api/v1/households/{$household->id}", ['name' => 'Renamed'])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.default_servings', 6);

    expect($household->fresh()->default_servings)->toBe(6);
});

it('rejects a default servings below one', function () {
    [$owner, $household] = ownerWithHousehold();
    Passport::actingAs($owner);

    $this->patchJson("/api/v1/households/{$household->id}", [
        'name' => $household->name,
        'default_servings' => 0,
    ])->assertJsonValidationErrors('default_servings');
});

it('exposes the active household default servings on /me', function () {
    [$owner, $household] = ownerWithHousehold();
    $household->update(['default_servings' => 3]);
    Passport::actingAs($owner);

    $this->getJson('/api/v1/me')
        ->assertSuccessful()
        ->assertJsonPath('data.current_household.default_servings', 3);
});

it('lets owners delete a household', function () {
    [$owner, $household] = ownerWithHousehold();
    Passport::actingAs($owner);

    $this->deleteJson("/api/v1/households/{$household->id}")->assertNoContent();
    $this->assertModelMissing($household);
});

it('switches the active household', function () {
    [$owner, $first] = ownerWithHousehold();
    $second = Household::factory()->create();
    $second->members()->attach($owner, ['role' => HouseholdRole::Owner->value]);

    Passport::actingAs($owner);
    $this->postJson('/api/v1/household/switch', ['household_id' => $second->id])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $second->id);

    expect($owner->fresh()->current_household_id)->toBe($second->id);
});

it('treats switching to a household you do not belong to as not found', function () {
    [$owner] = ownerWithHousehold();
    $other = Household::factory()->create();

    Passport::actingAs($owner);
    $this->postJson('/api/v1/household/switch', ['household_id' => $other->id])->assertNotFound();
});

describe('members', function () {
    it('lists members of the active household', function () {
        [$owner, $household] = ownerWithHousehold();
        $household->members()->attach(User::factory()->create(), ['role' => HouseholdRole::Member->value]);

        Passport::actingAs($owner);
        $this->getJson('/api/v1/household/members')
            ->assertSuccessful()
            ->assertJsonCount(2, 'data');
    });

    it('lets an owner change a member role', function () {
        [$owner, $household] = ownerWithHousehold();
        $member = User::factory()->create();
        $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);

        Passport::actingAs($owner);
        $this->patchJson("/api/v1/household/members/{$member->id}", ['role' => 'owner'])->assertNoContent();

        expect($household->isOwnedBy($member))->toBeTrue();
    });

    it('prevents demoting the last owner', function () {
        [$owner] = ownerWithHousehold();

        Passport::actingAs($owner);
        $this->patchJson("/api/v1/household/members/{$owner->id}", ['role' => 'member'])->assertConflict();
    });

    it('lets a member leave the household', function () {
        [, $household] = ownerWithHousehold();
        $member = User::factory()->create(['current_household_id' => $household->id]);
        $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);

        Passport::actingAs($member);
        $this->deleteJson("/api/v1/household/members/{$member->id}")->assertNoContent();

        expect($household->hasMember($member))->toBeFalse()
            ->and($member->fresh()->current_household_id)->toBeNull();
    });

    it('prevents removing the last owner', function () {
        [$owner] = ownerWithHousehold();

        Passport::actingAs($owner);
        $this->deleteJson("/api/v1/household/members/{$owner->id}")->assertConflict();
    });
});

it('sets up the first household once and preserves its settings on retry', function () {
    $user = User::factory()->create();
    Passport::actingAs($user);

    $id = $this->postJson('/api/v1/household/setup', ['name' => 'My Kitchen', 'default_servings' => 4])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'My Kitchen')
        ->assertJsonPath('data.default_servings', 4)
        ->json('data.id');

    $this->postJson('/api/v1/household/setup', ['name' => 'Retry', 'default_servings' => 2])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.name', 'My Kitchen')
        ->assertJsonPath('data.default_servings', 4);

    expect($user->fresh()->current_household_id)->toBe($id)
        ->and($user->households()->count())->toBe(1)
        ->and(Household::findOrFail($id)->isOwnedBy($user))->toBeTrue();
    $this->assertDatabaseCount('households', 1);
});

it('keeps the active household when setting up another device', function () {
    [$user, $household] = ownerWithHousehold();
    Passport::actingAs($user);

    $this->postJson('/api/v1/household/setup', ['name' => 'My Kitchen'])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $household->id);
    $this->assertDatabaseCount('households', 1);
});

it('restores an existing membership instead of creating an extra household', function () {
    [$user, $household] = ownerWithHousehold();
    $user->update(['current_household_id' => null]);
    Passport::actingAs($user);

    $this->postJson('/api/v1/household/setup', ['name' => 'My Kitchen'])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $household->id);

    expect($user->fresh()->current_household_id)->toBe($household->id);
    $this->assertDatabaseCount('households', 1);
});

it('never reuses an active household the user does not belong to', function () {
    [$user, $own] = ownerWithHousehold();
    $other = Household::factory()->create();
    $user->update(['current_household_id' => $other->id]);
    Passport::actingAs($user);

    $this->postJson('/api/v1/household/setup', ['name' => 'My Kitchen'])
        ->assertSuccessful()
        ->assertJsonPath('data.id', $own->id);

    expect($user->fresh()->current_household_id)->toBe($own->id);
    $this->assertDatabaseCount('households', 2);
});

it('requires authentication and valid settings for household setup', function () {
    $this->postJson('/api/v1/household/setup', ['name' => 'My Kitchen'])->assertUnauthorized();
    Passport::actingAs(User::factory()->create());
    $this->postJson('/api/v1/household/setup', ['name' => '', 'default_servings' => 0])
        ->assertJsonValidationErrors(['name', 'default_servings']);
    $this->assertDatabaseCount('households', 0);
});
