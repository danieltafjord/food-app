<?php

use App\Actions\ApiTokens\CreateApiToken;
use App\Models\ApiRequest;
use App\Models\ApiTokenDetail;
use App\Models\Ingredient;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;

test('mobile API requests are logged with the user, household and bodies', function () {
    [$user, $household] = ownerWithHousehold();
    Passport::actingAs($user);

    $this->withHeader('User-Agent', 'Handlelista/2.0 (iOS)')
        ->postJson('/api/v1/ingredients', ['name' => 'Milk', 'password' => 'hunter2'])
        ->assertCreated();

    $log = ApiRequest::query()->sole();
    expect($log)
        ->channel->toBe('app')
        ->user_id->toBe($user->id)
        ->household_id->toBe($household->id)
        ->api_token_detail_id->toBeNull()
        ->method->toBe('POST')
        ->path->toBe('api/v1/ingredients')
        ->route->toBe('api.v1.ingredients.store')
        ->status->toBe(201)
        ->user_agent->toBe('Handlelista/2.0 (iOS)')
        ->error->toBeNull();
    expect($log->request_body)->toContain('"name": "Milk"')->toContain('"password": "[redacted]"')->not->toContain('hunter2');
    expect($log->response_body)->toContain('"name": "Milk"');
    expect(Ingredient::query()->count())->toBe(1);
});

test('public API requests are logged with the token even when the write is rolled back', function () {
    [$user, $household] = ownerWithHousehold();
    $token = app(CreateApiToken::class)->handle($user, $household, 'Home Assistant', true)->accessToken;

    $this->withToken($token)->postJson('/api/public/v1/ingredients', ['name' => ''])->assertUnprocessable();

    $log = ApiRequest::query()->sole();
    expect($log)
        ->channel->toBe('public')
        ->user_id->toBe($user->id)
        ->household_id->toBe($household->id)
        ->api_token_detail_id->toBe(ApiTokenDetail::query()->sole()->id)
        ->status->toBe(422);
    expect($log->response_body)->toContain('The name field is required.');
});

test('rejected requests are logged without a user', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

    expect(ApiRequest::query()->orderBy('id')->get())
        ->toHaveCount(2)
        ->sequence(
            fn ($log) => $log->channel->toBe('app')->status->toBe(401)->user_id->toBeNull(),
            fn ($log) => $log->channel->toBe('mcp')->status->toBe(401)->request_body->toContain('tools/list'),
        );
});

test('server errors are logged with the exception', function () {
    Route::middleware(['api', 'api.log:app'])->get('/api/v1/boom', fn () => throw new RuntimeException('Kitchen on fire'));

    $this->getJson('/api/v1/boom')->assertStatus(500);

    $log = ApiRequest::query()->sole();
    expect($log->status)->toBe(500);
    expect($log->error)->toStartWith('RuntimeException: Kitchen on fire');
});

test('oversized bodies are truncated and query strings are kept', function () {
    [$user] = ownerWithHousehold();
    Passport::actingAs($user);

    $this->getJson('/api/v1/ingredients?search='.str_repeat('a', 20000))->assertOk();

    $log = ApiRequest::query()->sole();
    expect(strlen($log->request_body))->toBeLessThan(17000);
    expect($log->request_body)->toStartWith('{')->toContain('"query"')->toContain('[truncated, 2');
});

test('the request still succeeds when logging fails', function () {
    [$user] = ownerWithHousehold();
    Passport::actingAs($user);
    ApiRequest::creating(fn () => throw new RuntimeException('log store down'));

    $this->getJson('/api/v1/me')->assertOk();

    $this->assertDatabaseCount('api_requests', 0);
});

test('every response carries a request id that is stored on the log row', function () {
    [$user] = ownerWithHousehold();
    Passport::actingAs($user);

    $response = $this->getJson('/api/v1/me')->assertOk();

    $requestId = $response->headers->get('X-Request-Id');
    expect($requestId)->toMatch('/^[0-9a-f-]{36}$/');
    expect(ApiRequest::query()->sole()->request_id)->toBe($requestId);
});
