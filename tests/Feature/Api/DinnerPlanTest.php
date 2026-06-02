<?php

use App\Models\Dinner;
use App\Models\DinnerPlan;
use Laravel\Passport\Passport;

beforeEach(function () {
    [$this->user, $this->household] = ownerWithHousehold();
    Passport::actingAs($this->user);
});

it('creates a dinner plan', function () {
    $this->postJson('/api/v1/dinner-plans', [
        'name' => 'This week',
        'start_date' => '2026-06-08',
        'end_date' => '2026-06-14',
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.name', 'This week');

    $this->assertDatabaseHas('dinner_plans', [
        'household_id' => $this->household->id,
        'name' => 'This week',
    ]);
});

it('schedules a dinner on a day', function () {
    $plan = DinnerPlan::factory()->for($this->household)->create();
    $dinner = Dinner::factory()->for($this->household)->create();

    $this->postJson("/api/v1/dinner-plans/{$plan->id}/entries", [
        'dinner_id' => $dinner->id,
        'scheduled_date' => '2026-06-09',
        'servings' => 2,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.dinner_id', $dinner->id)
        ->assertJsonPath('data.servings', 2)
        ->assertJsonPath('data.meal_type', 'dinner');

    expect($plan->entries()->count())->toBe(1);
});

it('rejects scheduling a dinner from another household', function () {
    $plan = DinnerPlan::factory()->for($this->household)->create();
    $foreign = Dinner::factory()->create();

    $this->postJson("/api/v1/dinner-plans/{$plan->id}/entries", [
        'dinner_id' => $foreign->id,
        'scheduled_date' => '2026-06-09',
        'servings' => 2,
    ])->assertUnprocessable();
});

it('shows a plan with its entries', function () {
    $plan = DinnerPlan::factory()->for($this->household)->create();
    $dinner = Dinner::factory()->for($this->household)->create();
    $plan->entries()->create([
        'dinner_id' => $dinner->id,
        'scheduled_date' => '2026-06-09',
        'servings' => 2,
        'meal_type' => 'dinner',
    ]);

    $this->getJson("/api/v1/dinner-plans/{$plan->id}")
        ->assertSuccessful()
        ->assertJsonCount(1, 'data.entries')
        ->assertJsonPath('data.entries.0.dinner_name', $dinner->name);
});

it('updates and removes a plan entry', function () {
    $plan = DinnerPlan::factory()->for($this->household)->create();
    $dinner = Dinner::factory()->for($this->household)->create();
    $entry = $plan->entries()->create([
        'dinner_id' => $dinner->id,
        'scheduled_date' => '2026-06-09',
        'servings' => 2,
        'meal_type' => 'dinner',
    ]);

    $this->patchJson("/api/v1/dinner-plans/{$plan->id}/entries/{$entry->id}", [
        'dinner_id' => $dinner->id,
        'scheduled_date' => '2026-06-10',
        'servings' => 4,
    ])
        ->assertSuccessful()
        ->assertJsonPath('data.servings', 4);

    $this->deleteJson("/api/v1/dinner-plans/{$plan->id}/entries/{$entry->id}")->assertNoContent();

    expect($plan->entries()->count())->toBe(0);
});

it('hides another household plan', function () {
    $foreign = DinnerPlan::factory()->create();

    $this->getJson("/api/v1/dinner-plans/{$foreign->id}")->assertNotFound();
});

it('deletes a plan', function () {
    $plan = DinnerPlan::factory()->for($this->household)->create();

    $this->deleteJson("/api/v1/dinner-plans/{$plan->id}")->assertNoContent();
    $this->assertModelMissing($plan);
});
