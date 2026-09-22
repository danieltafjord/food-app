<?php

use App\Models\AiRequest;
use App\Models\Dinner;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

test('the analytics page aggregates app and AI usage over the selected window', function () {
    $this->travelTo('2026-09-22 10:00:00');
    $admin = User::factory()->admin()->create(['ai_suggestions_enabled' => true]);
    [$user, $household] = ownerWithHousehold();
    User::factory()->unverified()->create(['created_at' => now()->subDays(40)]);
    Dinner::factory()->for($household)->create();
    ShoppingList::factory()->for($household)->create();
    $staleHousehold = Household::factory()->create();
    $staleDinner = Dinner::factory()->for($staleHousehold)->create();
    // The sync hook stamps synced_at on save, so backdate it directly.
    DB::table('dinners')->where('id', $staleDinner->id)->update(['synced_at' => now()->subDays(10)]);

    AiRequest::factory()->count(2)->for($user)->for($household)->create(['feature' => 'suggestions', 'model' => 'google/gemini-3.5-flash-lite', 'duration_ms' => 400, 'input_tokens' => 100, 'output_tokens' => 10, 'cost' => 0.001]);
    AiRequest::factory()->cached()->for($user)->for($household)->create(['feature' => 'suggestions', 'model' => 'google/gemini-3.5-flash-lite']);
    AiRequest::factory()->failed()->for($user)->for($household)->create(['feature' => 'categorization', 'model' => 'typesafe/jev-1.13', 'created_at' => now()->subDays(2)]);
    AiRequest::factory()->for($user)->for($household)->create(['feature' => 'categorization', 'created_at' => now()->subDays(45)]);
    DB::table('ai_daily_usage')->insert(['day' => '2026-09-22', 'scope' => 'global', 'feature' => 'suggestions', 'used' => 7]);

    $this->actingAs($admin)
        ->get(route('admin.analytics', ['days' => 30]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Analytics')
            ->where('analytics.days', 30)
            ->where('analytics.from', '2026-08-24')
            ->where('analytics.to', '2026-09-22')
            ->where('analytics.app.totals.users', 3)
            ->where('analytics.app.totals.verified_users', 2)
            ->where('analytics.app.totals.new_users', 2)
            ->where('analytics.app.totals.households', 2)
            ->where('analytics.app.totals.active_households', 1)
            ->where('analytics.app.totals.dinners', 2)
            ->where('analytics.app.totals.shopping_lists', 1)
            ->has('analytics.app.signups', 30)
            ->where('analytics.app.signups.29', ['day' => '2026-09-22', 'value' => 2])
            ->where('analytics.ai.totals.requests', 4)
            ->where('analytics.ai.totals.provider_requests', 3)
            ->where('analytics.ai.totals.cached', 1)
            ->where('analytics.ai.totals.failed', 1)
            ->where('analytics.ai.totals.avg_duration_ms', 400)
            ->where('analytics.ai.totals.input_tokens', 200)
            ->where('analytics.ai.totals.output_tokens', 20)
            ->where('analytics.ai.totals.cost', 0.002)
            ->where('analytics.ai.totals.suggestions_users', 1)
            ->where('analytics.ai.totals.categorization_users', 0)
            ->has('analytics.ai.daily.suggestions', 30)
            ->where('analytics.ai.daily.suggestions.29', ['day' => '2026-09-22', 'ok' => 2, 'cached' => 1, 'failed' => 0])
            ->where('analytics.ai.daily.categorization.27', ['day' => '2026-09-20', 'ok' => 0, 'cached' => 0, 'failed' => 1])
            ->where('analytics.ai.models.0.model', 'google/gemini-3.5-flash-lite')
            ->where('analytics.ai.models.0.requests', 3)
            ->where('analytics.ai.today.1', ['feature' => 'suggestions', 'used' => 7, 'limit' => config('assistance.limits.suggestions.global')])
            ->has('analytics.ai.households', 1)
            ->where('analytics.ai.households.0', ['household_id' => $household->id, 'name' => $household->name, 'requests' => 4, 'failed' => 1, 'users' => 1, 'cost' => 0.002])
            ->where('cacheSeconds', 300)
            ->has('generatedAt')
        );
});

test('the analytics are cached briefly and can be recounted on demand', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get(route('admin.analytics'))
        ->assertInertia(fn (Assert $page) => $page->where('analytics.app.totals.users', 1));

    User::factory()->create();

    $this->actingAs($admin)->get(route('admin.analytics'))
        ->assertInertia(fn (Assert $page) => $page->where('analytics.app.totals.users', 1));

    $this->actingAs($admin)->get(route('admin.analytics', ['refresh' => 1]))
        ->assertInertia(fn (Assert $page) => $page->where('analytics.app.totals.users', 2));
});

test('an unsupported window falls back to 30 days', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.analytics', ['days' => 12]))
        ->assertInertia(fn (Assert $page) => $page->where('analytics.days', 30)->has('analytics.app.signups', 30));
});
