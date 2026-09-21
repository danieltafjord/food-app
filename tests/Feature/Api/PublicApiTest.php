<?php

use App\Actions\ApiTokens\CreateApiToken;
use App\Actions\Households\RemoveMember;
use App\Enums\HouseholdRole;
use App\Models\ApiTokenDetail;
use App\Models\Dinner;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\User;
use Laravel\Passport\Passport;

/**
 * Issue a real API token and return its plain-text bearer value.
 */
function issueApiToken(User $user, Household $household, bool $canWrite = false): string
{
    return app(CreateApiToken::class)->handle($user, $household, 'Test token', $canWrite)->accessToken;
}

test('a read token can read its household\'s data', function () {
    [$user, $household] = ownerWithHousehold();
    Ingredient::factory()->for($household)->create(['name' => 'Milk']);
    Ingredient::factory()->for(Household::factory())->create(['name' => 'Foreign']);

    $this->withToken(issueApiToken($user, $household))
        ->getJson('/api/public/v1/ingredients')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Milk');

    expect(ApiTokenDetail::query()->sole()->last_used_at)->not->toBeNull();
});

test('a read token cannot write', function () {
    [$user, $household] = ownerWithHousehold();

    $this->withToken(issueApiToken($user, $household))
        ->postJson('/api/public/v1/ingredients', ['name' => 'Eggs'])
        ->assertForbidden();

    expect($household->ingredients()->count())->toBe(0);
});

test('a write token can create and delete', function () {
    [$user, $household] = ownerWithHousehold();
    $token = issueApiToken($user, $household, canWrite: true);

    $id = $this->withToken($token)
        ->postJson('/api/public/v1/ingredients', ['name' => 'Eggs'])
        ->assertSuccessful()
        ->json('data.id');

    expect($household->ingredients()->whereKey($id)->exists())->toBeTrue();

    $this->withToken($token)
        ->deleteJson("/api/public/v1/ingredients/{$id}")
        ->assertNoContent();
});

test('the token is pinned to its household even when the user switches household', function () {
    [$user, $household] = ownerWithHousehold();
    $token = issueApiToken($user, $household);

    $otherHousehold = Household::factory()->create();
    $otherHousehold->members()->attach($user, ['role' => HouseholdRole::Owner->value]);
    $user->update(['current_household_id' => $otherHousehold->id]);
    $foreignIngredient = Ingredient::factory()->for($otherHousehold)->create();

    $this->withToken($token)
        ->getJson('/api/public/v1/me')
        ->assertOk()
        ->assertJsonPath('data.household_id', $household->id)
        ->assertJsonPath('data.scopes', ['read']);

    $this->withToken($token)
        ->getJson("/api/public/v1/ingredients/{$foreignIngredient->id}")
        ->assertNotFound();
});

test('today returns what is planned for today', function () {
    [$user, $household] = ownerWithHousehold();
    $plan = DinnerPlan::factory()->for($household)->create();
    $dinner = Dinner::factory()->for($household)->create(['name' => 'Tacos']);
    DinnerPlanEntry::factory()->for($plan)->for($dinner)->create(['scheduled_date' => today()]);
    DinnerPlanEntry::factory()->for($plan)->for($dinner)->create(['scheduled_date' => today()->addDay()]);

    $this->withToken(issueApiToken($user, $household))
        ->getJson('/api/public/v1/today')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.dinner_name', 'Tacos');
});

test('api tokens cannot call the mobile api', function () {
    [$user, $household] = ownerWithHousehold();

    $this->withToken(issueApiToken($user, $household, canWrite: true))
        ->getJson('/api/v1/me')
        ->assertForbidden();
});

test('mobile tokens cannot call the public api', function () {
    [$user] = ownerWithHousehold();
    Passport::actingAs($user);

    $this->getJson('/api/public/v1/ingredients')->assertForbidden();
});

test('a revoked token is rejected', function () {
    [$user, $household] = ownerWithHousehold();
    $token = issueApiToken($user, $household);
    ApiTokenDetail::query()->sole()->token->revoke();

    $this->withToken($token)
        ->getJson('/api/public/v1/ingredients')
        ->assertUnauthorized();
});

test('removing the member revokes their tokens for that household', function () {
    [$owner, $household] = ownerWithHousehold();
    $member = User::factory()->create();
    $household->members()->attach($member, ['role' => HouseholdRole::Member->value]);
    $token = issueApiToken($member, $household);

    app(RemoveMember::class)->handle($household, $member);

    $this->withToken($token)
        ->getJson('/api/public/v1/ingredients')
        ->assertUnauthorized();
});

test('api tokens are not listed as devices', function () {
    [$user, $household] = ownerWithHousehold();
    issueApiToken($user, $household);
    Passport::actingAs($user);

    $this->getJson('/api/v1/auth/devices')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});
