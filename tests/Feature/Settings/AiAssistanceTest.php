<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the AI assistance page shows the switches, limits and remaining budget', function () {
    [$user] = ownerWithHousehold();
    $user->forceFill(['ai_suggestions_enabled' => true])->save();

    $this->actingAs($user)
        ->get(route('ai-assistance.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/AiAssistance')
            ->where('settings', ['categorization_enabled' => false, 'suggestions_enabled' => true])
            ->where('available', false)
            ->has('features', 2)
            ->where('features.0.key', 'categorization')
            ->where('features.0.daily_limit', config('assistance.limits.categorization.user'))
            ->where('usage.suggestions.remaining', config('assistance.limits.suggestions.user'))
        );
});

test('a user without a household still sees the page', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('ai-assistance.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('usage', null));
});

test('a user can turn the AI features on and off', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch(route('ai-assistance.update'), ['categorization_enabled' => true, 'suggestions_enabled' => false])
        ->assertRedirect(route('ai-assistance.edit'))
        ->assertSessionHasNoErrors();

    expect($user->refresh()->ai_categorization_enabled)->toBeTrue()
        ->and($user->ai_suggestions_enabled)->toBeFalse();

    $this->actingAs($user)
        ->patch(route('ai-assistance.update'), ['categorization_enabled' => 'maybe'])
        ->assertSessionHasErrors(['categorization_enabled', 'suggestions_enabled']);
});

test('guests and unverified users cannot change AI settings', function () {
    $this->patch(route('ai-assistance.update'), ['categorization_enabled' => true, 'suggestions_enabled' => true])
        ->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    $this->actingAs($unverified)->get(route('ai-assistance.edit'))->assertRedirect(route('verification.notice'));
    expect($unverified->refresh()->ai_categorization_enabled)->toBeFalse();
});
