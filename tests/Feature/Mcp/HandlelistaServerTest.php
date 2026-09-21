<?php

use App\Actions\ApiTokens\CreateApiToken;
use App\Models\Dinner;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\ShoppingList;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

function mcpToken(User $user, Household $household, bool $canWrite = false): string
{
    return app(CreateApiToken::class)->handle($user, $household, 'MCP token', $canWrite)->accessToken;
}

/**
 * @param  array<string, mixed>  $params
 */
function mcpCall(string $token, string $method, array $params = []): TestResponse
{
    return test()->withToken($token)->postJson('/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]);
}

test('the mcp server requires an api token', function () {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

    [$user] = ownerWithHousehold();
    Passport::actingAs($user);

    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();
});

test('a read token is only offered read tools', function () {
    [$user, $household] = ownerWithHousehold();

    $names = mcpCall(mcpToken($user, $household), 'tools/list')->assertOk()->json('result.tools.*.name');

    expect($names)->toContain('get-today-tool', 'list-shopping-lists-tool')
        ->not->toContain('add-shopping-list-item-tool');
});

test('a read token cannot call a write tool', function () {
    [$user, $household] = ownerWithHousehold();
    $list = ShoppingList::factory()->for($household)->create();

    mcpCall(mcpToken($user, $household), 'tools/call', [
        'name' => 'add-shopping-list-item-tool',
        'arguments' => ['shopping_list_id' => $list->id, 'name' => 'Milk'],
    ])->assertJsonMissingPath('result.content');

    expect($list->items()->count())->toBe(0);
});

test('get today returns the planned dinner', function () {
    [$user, $household] = ownerWithHousehold();
    $plan = DinnerPlan::factory()->for($household)->create();
    $dinner = Dinner::factory()->for($household)->create(['name' => 'Tacos']);
    DinnerPlanEntry::factory()->for($plan)->for($dinner)->create(['scheduled_date' => today()]);

    $response = mcpCall(mcpToken($user, $household), 'tools/call', ['name' => 'get-today-tool', 'arguments' => []]);

    $response->assertOk()->assertJsonPath('result.isError', false);
    expect($response->json('result.content.0.text'))->toContain('Tacos');
});

test('a write token can add and check off a shopping list item', function () {
    [$user, $household] = ownerWithHousehold();
    $list = ShoppingList::factory()->for($household)->create();
    $token = mcpToken($user, $household, canWrite: true);

    mcpCall($token, 'tools/call', [
        'name' => 'add-shopping-list-item-tool',
        'arguments' => ['shopping_list_id' => $list->id, 'name' => 'Milk', 'quantity' => 2, 'unit' => 'l'],
    ])->assertOk()->assertJsonPath('result.isError', false);

    $item = $list->items()->sole();
    expect($item->name)->toBe('Milk');

    mcpCall($token, 'tools/call', [
        'name' => 'check-shopping-list-item-tool',
        'arguments' => ['shopping_list_id' => $list->id, 'item_id' => $item->id, 'checked' => true],
    ])->assertOk()->assertJsonPath('result.isError', false);

    expect($item->fresh()->is_checked)->toBeTrue();
});

test('tools cannot reach another household\'s data', function () {
    [$user, $household] = ownerWithHousehold();
    $foreignList = ShoppingList::factory()->for(Household::factory())->create();

    $response = mcpCall(mcpToken($user, $household, canWrite: true), 'tools/call', [
        'name' => 'add-shopping-list-item-tool',
        'arguments' => ['shopping_list_id' => $foreignList->id, 'name' => 'Milk'],
    ]);

    expect($response->json('result.isError') ?? true)->toBeTrue()
        ->and($foreignList->items()->count())->toBe(0);
});
