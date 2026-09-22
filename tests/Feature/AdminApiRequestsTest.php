<?php

use App\Models\AiRequest;
use App\Models\ApiRequest;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the API request log lists requests newest first with filters', function () {
    $admin = User::factory()->admin()->create();
    [$user, $household] = ownerWithHousehold();
    $user->update(['name' => 'Bob Builder']);

    $ok = ApiRequest::factory()->for($user)->for($household)->create(['path' => 'api/v1/sync', 'route' => 'api.v1.sync', 'method' => 'POST', 'created_at' => now()->subMinutes(3)]);
    $notFound = ApiRequest::factory()->public()->for($user)->for($household)->create(['status' => 404, 'path' => 'api/public/v1/dinners/99', 'created_at' => now()->subMinutes(2)]);
    $crashed = ApiRequest::factory()->failed()->create(['channel' => ApiRequest::CHANNEL_MCP, 'path' => 'mcp', 'method' => 'POST', 'created_at' => now()->subMinute()]);

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/ApiRequests')
            ->has('requests.data', 3)
            ->where('requests.data.0.id', $crashed->id)
            ->where('requests.data.0.channel', 'mcp')
            ->where('requests.data.0.status', 500)
            ->where('requests.data.0.has_error', true)
            ->where('requests.data.1.id', $notFound->id)
            ->where('requests.data.1.user.name', 'Bob Builder')
            ->where('requests.data.2.id', $ok->id)
            ->where('requests.data.2.has_error', false)
            ->where('filters', ['channel' => 'all', 'outcome' => 'all', 'method' => '', 'user' => null, 'search' => '', 'sort' => 'created', 'direction' => 'desc', 'per_page' => 50])
            ->where('pageSizes', [25, 50, 100])
            ->where('errorGroups', [['route' => 'api.v1.me', 'method' => 'POST', 'exception' => 'RuntimeException', 'count' => 1, 'latest_id' => $crashed->id, 'latest_at' => $crashed->created_at->toIso8601String()]])
            ->where('channels', ['app' => 'App', 'public' => 'Public API', 'mcp' => 'MCP'])
        );

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['outcome' => 'errors']))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 2)->where('requests.data.0.id', $crashed->id)->where('requests.data.1.id', $notFound->id));

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['outcome' => 'server_errors']))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $crashed->id));

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['channel' => 'public', 'user' => $user->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('requests.data', 1)
            ->where('requests.data.0.id', $notFound->id)
            ->where('filters.user', ['id' => $user->id, 'name' => 'Bob Builder'])
        );

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['method' => 'post', 'search' => 'sync']))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $ok->id)->where('filters.method', 'POST'));

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['search' => '404']))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $notFound->id));

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['search' => $ok->request_id]))
        ->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $ok->id));
});

test('repeated server errors are grouped by route and exception with the newest example', function () {
    $admin = User::factory()->admin()->create();
    ApiRequest::factory()->failed()->count(3)->create(['route' => 'api.v1.sync', 'method' => 'POST', 'error' => "RuntimeException: boom\n/app/Foo.php:1"]);
    $newest = ApiRequest::factory()->failed()->create(['route' => 'api.v1.sync', 'method' => 'POST', 'error' => "RuntimeException: other message\n/app/Foo.php:2", 'created_at' => now()->addMinute()]);
    ApiRequest::factory()->failed()->create(['route' => 'api.v1.sync', 'method' => 'POST', 'error' => 'TypeError: nope']);
    ApiRequest::factory()->failed()->create(['route' => 'api.v1.sync', 'method' => 'POST', 'error' => 'RuntimeException: old', 'created_at' => now()->subHours(25)]);
    ApiRequest::factory()->create(['status' => 404]);

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('errorGroups', 2)
            ->where('errorGroups.0.route', 'api.v1.sync')
            ->where('errorGroups.0.exception', 'RuntimeException')
            ->where('errorGroups.0.count', 4)
            ->where('errorGroups.0.latest_id', $newest->id)
            ->where('errorGroups.1.exception', 'TypeError')
            ->where('errorGroups.1.count', 1)
        );
});

test('the API request log can be sorted and its page size changed', function () {
    $admin = User::factory()->admin()->create();
    $slow = ApiRequest::factory()->create(['status' => 200, 'duration_ms' => 900, 'created_at' => now()->subMinutes(3)]);
    $notFound = ApiRequest::factory()->create(['status' => 404, 'duration_ms' => 50, 'created_at' => now()->subMinutes(2)]);
    $crashed = ApiRequest::factory()->failed()->create(['duration_ms' => 300, 'created_at' => now()->subMinute()]);

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['sort' => 'duration', 'direction' => 'desc']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.id', $slow->id)
            ->where('requests.data.2.id', $notFound->id)
            ->where('filters.sort', 'duration')
            ->where('filters.direction', 'desc'));

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['sort' => 'status', 'direction' => 'asc']))
        ->assertInertia(fn (Assert $page) => $page->where('requests.data.0.id', $slow->id)->where('requests.data.2.id', $crashed->id));

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['sort' => 'bogus', 'direction' => 'sideways', 'per_page' => 7]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('requests.data.0.id', $crashed->id)
            ->where('filters.sort', 'created')
            ->where('filters.direction', 'desc')
            ->where('filters.per_page', 50));

    $this->actingAs($admin)
        ->get(route('admin.api-requests.index', ['per_page' => 100]))
        ->assertInertia(fn (Assert $page) => $page->where('filters.per_page', 100)->where('requests.per_page', 100));
});

test('an API request can be opened to see the request, response and error', function () {
    $admin = User::factory()->admin()->create();
    [$user, $household] = ownerWithHousehold();
    $request = ApiRequest::factory()->failed()->for($user)->for($household)->create([
        'request_id' => '11111111-2222-4333-8444-555555555555',
        'method' => 'POST',
        'path' => 'api/v1/dinners',
        'request_body' => '{"name": "Tacos"}',
        'ip' => '10.0.0.5',
        'user_agent' => 'Handlelista/2.0',
        'created_at' => '2026-09-22 10:00:00',
    ]);
    $aiRequest = AiRequest::factory()->failed()->for($user)->for($household)->create(['request_id' => $request->request_id, 'feature' => 'categorization']);
    AiRequest::factory()->for($user)->for($household)->create();

    $this->actingAs($admin)
        ->get(route('admin.api-requests.show', $request))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/ApiRequestShow')
            ->where('request.id', $request->id)
            ->where('request.status', 500)
            ->where('request.request_body', '{"name": "Tacos"}')
            ->where('request.response_body', '{"message":"Server Error"}')
            ->where('request.error', 'RuntimeException: Something broke')
            ->where('request.ip', '10.0.0.5')
            ->where('request.user_agent', 'Handlelista/2.0')
            ->where('request.user.id', $user->id)
            ->where('request.household.name', $household->name)
            ->where('request.token_name', null)
            ->where('request.retained_until', '2026-10-22T10:00:00+00:00')
            ->where('request.request_id', '11111111-2222-4333-8444-555555555555')
            ->where('request.ai_requests', [['id' => $aiRequest->id, 'feature' => 'categorization', 'status' => 'failed']])
        );
});

test('the API request log is only for admins', function () {
    $request = ApiRequest::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('admin.api-requests.show', $request))->assertForbidden();
});

test('old API request rows are pruned', function () {
    $old = ApiRequest::factory()->create(['created_at' => now()->subDays(31)]);
    $recent = ApiRequest::factory()->create(['created_at' => now()->subDays(29)]);

    $this->artisan('model:prune', ['--model' => [ApiRequest::class]])->assertSuccessful();

    $this->assertModelMissing($old);
    $this->assertModelExists($recent);
});
