<?php

use App\Actions\ApiTokens\CreateApiToken;
use App\Models\Dinner;
use App\Models\DinnerItem;
use App\Models\DinnerPlan;
use App\Models\DinnerPlanEntry;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
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

function mcpData(TestResponse $response): array
{
    $response->assertOk()->assertJsonPath('result.isError', false);

    return json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR);
}

test('catalogue tools search and page only the token household', function (string $tool, string $model) {
    [$user, $household] = ownerWithHousehold();
    $rows = $model::factory()->count(3)->for($household)->sequence(
        ['name' => 'Review A'], ['name' => 'Review B'], ['name' => 'Unrelated'],
    )->create();
    $model::factory()->create(['name' => 'Review foreign']);
    $token = mcpToken($user, $household);

    $first = mcpData(mcpCall($token, 'tools/call', ['name' => $tool, 'arguments' => ['search' => 'Review', 'limit' => 1]]));
    $second = mcpData(mcpCall($token, 'tools/call', ['name' => $tool, 'arguments' => ['search' => 'Review', 'limit' => 1, 'after_id' => $first['next_cursor']]]));

    expect($first['data'])->toHaveCount(1);
    expect($first['data'][0]['id'])->toBe($rows[0]->id);
    expect($second['data'])->toHaveCount(1);
    expect($second['data'][0]['id'])->toBe($rows[1]->id);
    expect($second['next_cursor'])->toBeNull();
})->with([
    ['list-dinners-tool', Dinner::class],
    ['list-dinner-plans-tool', DinnerPlan::class],
    ['list-shopping-lists-tool', ShoppingList::class],
    ['list-ingredients-tool', Ingredient::class],
]);

test('dinner details page ingredients without loading the full recipe into list results', function () {
    [$user, $household] = ownerWithHousehold();
    $dinner = Dinner::factory()->for($household)->create(['notes' => 'Keep chilled']);
    $items = DinnerItem::factory()->count(3)->for($dinner)->create();
    $token = mcpToken($user, $household);

    $summary = mcpData(mcpCall($token, 'tools/call', ['name' => 'list-dinners-tool', 'arguments' => []]));
    $first = mcpData(mcpCall($token, 'tools/call', ['name' => 'get-dinner-tool', 'arguments' => ['dinner_id' => $dinner->id, 'limit' => 2]]));
    $last = mcpData(mcpCall($token, 'tools/call', ['name' => 'get-dinner-tool', 'arguments' => ['dinner_id' => $dinner->id, 'after_id' => $first['next_cursor']]]));

    expect($summary['data'][0])->item_count->toBe(3)->not->toHaveKey('items');
    expect($first['record']['notes'])->toBe('Keep chilled');
    expect($first['data'])->toHaveCount(2);
    expect($last['data'])->toHaveCount(1);
    expect($last['data'][0]['id'])->toBe($items[2]->id);
    expect($last['next_cursor'])->toBeNull();
});

test('detail tools reject foreign household records', function (string $tool, string $key, string $model) {
    [$user, $household] = ownerWithHousehold();
    $foreign = $model::factory()->create();

    $response = mcpCall(mcpToken($user, $household), 'tools/call', ['name' => $tool, 'arguments' => [$key => $foreign->id]]);

    expect($response->json('result.isError') ?? true)->toBeTrue();
    expect($response->json('result.content.0.text') ?? '')->not->toContain($foreign->name);
})->with([
    ['get-dinner-tool', 'dinner_id', Dinner::class],
    ['get-dinner-plan-tool', 'dinner_plan_id', DinnerPlan::class],
    ['get-shopping-list-tool', 'shopping_list_id', ShoppingList::class],
]);

test('catalogue tools reject unbounded pages', function () {
    [$user, $household] = ownerWithHousehold();

    mcpCall(mcpToken($user, $household), 'tools/call', ['name' => 'list-ingredients-tool', 'arguments' => ['limit' => 51]])
        ->assertOk()->assertJsonPath('result.isError', true);
});

test('plan details and shopping list details paginate their contents', function (string $tool, string $key, string $model, string $child, string $relationship) {
    [$user, $household] = ownerWithHousehold();
    $record = $model::factory()->for($household)->create();
    $children = $child::factory()->count(3)->for($record, $relationship)->create();
    $token = mcpToken($user, $household);

    $first = mcpData(mcpCall($token, 'tools/call', ['name' => $tool, 'arguments' => [$key => $record->id, 'limit' => 2]]));
    $last = mcpData(mcpCall($token, 'tools/call', ['name' => $tool, 'arguments' => [$key => $record->id, 'after_id' => $first['next_cursor']]]));

    expect($first['record']['id'])->toBe($record->id);
    expect($first['data'])->toHaveCount(2);
    expect($last['data'])->toHaveCount(1);
    expect($last['data'][0]['id'])->toBe($children[2]->id);
    expect($last['next_cursor'])->toBeNull();
})->with([
    ['get-dinner-plan-tool', 'dinner_plan_id', DinnerPlan::class, DinnerPlanEntry::class, 'dinnerPlan'],
    ['get-shopping-list-tool', 'shopping_list_id', ShoppingList::class, ShoppingListItem::class, 'shoppingList'],
]);
