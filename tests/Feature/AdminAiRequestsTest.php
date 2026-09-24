<?php

use App\Models\AiRequest;
use App\Models\ApiRequest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the AI request log lists requests newest first with filters', function () {
    $admin = User::factory()->admin()->create();
    [$user, $household] = ownerWithHousehold();
    $user->update(['name' => 'Bob Builder']);
    $other = User::factory()->create();

    $ok = AiRequest::factory()->for($user)->for($household)->create(['feature' => 'categorization', 'request' => ['name' => 'pak choi', 'locale' => 'nb'], 'created_at' => now()->subMinutes(3)]);
    $failed = AiRequest::factory()->failed()->for($user)->for($household)->create(['feature' => 'suggestions', 'request_id' => '11111111-2222-4333-8444-555555555555', 'request' => ['name' => 'Tacos'], 'error' => 'RequestException: HTTP 502', 'created_at' => now()->subMinutes(2)]);
    $cached = AiRequest::factory()->cached()->for($other)->create(['feature' => 'categorization', 'request' => ['name' => 'milk'], 'created_at' => now()->subMinute()]);

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/AiRequests')
            ->has('requests.data', 3)
            ->where('requests.data.0.id', $cached->id)
            ->where('requests.data.1.id', $failed->id)
            ->where('requests.data.1.status', 'failed')
            ->where('requests.data.1.summary', 'Tacos')
            ->where('requests.data.1.has_error', true)
            ->where('requests.data.1.user.name', 'Bob Builder')
            ->where('requests.data.1.household.name', $household->name)
            ->where('requests.data.2.id', $ok->id)
            ->where('filters', ['status' => 'all', 'feature' => 'all', 'user' => null, 'household' => null, 'search' => '', 'sort' => 'created', 'direction' => 'desc', 'per_page' => 50])
            ->where('pageSizes', [25, 50, 100])
            ->where('features', AiRequest::featureLabels())
            ->where('errorGroups', [['feature' => 'suggestions', 'model' => $failed->model, 'exception' => 'RequestException', 'count' => 1, 'latest_id' => $failed->id, 'latest_at' => $failed->created_at->toIso8601String()]])
        );

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['search' => '11111111-2222-4333-8444-555555555555']))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $failed->id));

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['status' => 'failed']))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $failed->id));

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['feature' => 'categorization', 'user' => $user->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $ok->id)
            ->where('filters.user', ['id' => $user->id, 'name' => 'Bob Builder'])
        );

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['search' => 'HTTP 502']))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $failed->id));

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['search' => 'pak choi', 'status' => 'bogus']))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $ok->id)->where('filters.status', 'all'));
});

test('the AI request log can be sorted and its page size changed', function () {
    $admin = User::factory()->admin()->create();
    $slow = AiRequest::factory()->create(['duration_ms' => 900, 'input_tokens' => 10, 'output_tokens' => 5, 'cost' => 0.001, 'created_at' => now()->subMinutes(3)]);
    $cheap = AiRequest::factory()->create(['duration_ms' => 100, 'input_tokens' => 1, 'output_tokens' => 1, 'cost' => 0.00001, 'created_at' => now()->subMinutes(2)]);
    $big = AiRequest::factory()->create(['duration_ms' => 400, 'input_tokens' => 500, 'output_tokens' => 50, 'cost' => 0.0005, 'created_at' => now()->subMinute()]);

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['sort' => 'duration', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.id', $slow->id)
            ->where('requests.data.2.id', $cheap->id)
            ->where('filters.sort', 'duration')
            ->where('filters.direction', 'desc'));

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['sort' => 'tokens', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.id', $cheap->id)->where('requests.data.2.id', $big->id));

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['sort' => 'cost', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.id', $cheap->id)->where('requests.data.2.id', $slow->id));

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['sort' => 'bogus', 'direction' => 'sideways', 'per_page' => 7]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.id', $big->id)
            ->where('filters.sort', 'created')
            ->where('filters.direction', 'desc')
            ->where('filters.per_page', 50));

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.index', ['per_page' => 25]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.per_page', 25)->where('requests.per_page', 25));
});

test('an AI request can be opened to see what was sent and what came back', function () {
    $admin = User::factory()->admin()->create();
    [$user, $household] = ownerWithHousehold();
    $apiRequest = ApiRequest::factory()->for($user)->for($household)->create(['status' => 200]);
    $request = AiRequest::factory()->for($user)->for($household)->create([
        'request_id' => $apiRequest->request_id,
        'feature' => 'categorization',
        'input_tokens' => 120,
        'output_tokens' => 8,
        'request' => ['name' => 'pak choi', 'locale' => 'nb'],
        'response' => ['data' => ['category' => 'produce'], 'raw' => ['answers' => ['category' => ['choice' => 'produce', 'confidence' => 0.98]]]],
        'created_at' => '2026-09-22 10:00:00',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.ai-requests.show', $request))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/AiRequestShow')
            ->where('request.id', $request->id)
            ->where('request.status', 'ok')
            ->where('request.input_tokens', 120)
            ->where('request.output_tokens', 8)
            ->where('request.request', ['name' => 'pak choi', 'locale' => 'nb'])
            ->where('request.response.data.category', 'produce')
            ->where('request.response.raw.answers.category.choice', 'produce')
            ->where('request.error', null)
            ->where('request.user.id', $user->id)
            ->where('request.household.id', $household->id)
            ->where('request.bodies_retained_until', '2026-10-22T10:00:00+00:00')
            ->where('request.request_id', $apiRequest->request_id)
            ->where('request.api_request', ['id' => $apiRequest->id, 'status' => 200])
        );
});

test('the AI request log is only for admins', function () {
    $request = AiRequest::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('admin.ai-requests.show', $request))->assertForbidden();
});

test('stored AI bodies are cleared after the retention period', function () {
    $old = AiRequest::factory()->create(['request' => ['name' => 'old'], 'response' => ['data' => []], 'created_at' => now()->subDays(31)]);
    $recent = AiRequest::factory()->failed()->create(['request' => ['name' => 'new'], 'error' => 'boom', 'created_at' => now()->subDays(29)]);

    $this->artisan('ai:prune-request-bodies')->assertSuccessful();

    expect($old->refresh())->request->toBeNull()->response->toBeNull()->status->toBe('ok');
    expect($recent->refresh())->request->toBe(['name' => 'new'])->error->toBe('boom');
});
