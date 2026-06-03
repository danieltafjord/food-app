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

it('forbids switching to a household you do not belong to', function () {
    [$owner] = ownerWithHousehold();
    $other = Household::factory()->create();

    Passport::actingAs($owner);
    $this->postJson('/api/v1/household/switch', ['household_id' => $other->id])->assertForbidden();
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
