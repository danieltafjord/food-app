<?php

use App\Actions\Ai\GenerateWeekDinners;
use App\Actions\Ai\ReviewDinnerRecipes;
use App\Models\AiRequest;
use App\Models\Ingredient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-server-key']);
});

function weekSuggestionInput(array $overrides = []): array
{
    return array_replace(['count' => 1, 'servings' => 2, 'locale' => 'nb', 'preferences' => '',
        'shortcuts' => [], 'exclude' => [], 'available' => []], $overrides);
}

function suggestedDinner(array $overrides = []): array
{
    return array_replace(['existing_id' => null, 'name' => 'Tomatpasta', 'category' => 'vegetarian',
        'notes' => 'Kok pastaen. Varm tomatene og bland alt sammen.', 'ingredients' => [
            ['name' => 'Pasta', 'quantity' => 200, 'unit' => 'g'],
            ['name' => 'Tomat', 'quantity' => 300, 'unit' => 'g'],
        ]], $overrides);
}

it('passes ingredient reuse context and exclusions to generation and independently reviews the result', function () {
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner()]]]);
    ReviewDinnerRecipes::fake([['issues' => []]]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput([
        'reuse_ingredients' => ['Tomat'], 'excluded_ingredients' => ['Reker'],
    ]))->assertOk();

    GenerateWeekDinners::assertPrompted(fn ($prompt) => json_decode($prompt->prompt, true)['reuse_ingredients'] === ['Tomat']
        && json_decode($prompt->prompt, true)['excluded_ingredients'] === ['Reker']);
    ReviewDinnerRecipes::assertPrompted(fn ($prompt) => json_decode($prompt->prompt, true)['dinners'][0]['notes'] === suggestedDinner()['notes']
        && json_decode($prompt->prompt, true)['excluded_ingredients'] === ['Reker']);
});

it('rejects excluded ingredients in new and reused dinners before reviewing', function (bool $reuse) {
    $id = (string) Str::uuid();
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner(['existing_id' => $reuse ? $id : null])]]]);
    ReviewDinnerRecipes::fake()->preventStrayPrompts();

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput([
        'excluded_ingredients' => [' pasta '],
        'available' => [['id' => $id, 'name' => 'Family pasta', 'category' => 'vegetarian', 'ingredients' => ['Pasta']]],
    ]))->assertServiceUnavailable();

    ReviewDinnerRecipes::assertNeverPrompted();
})->with([false, true]);

it('rejects failed recipe reviews without caching or returning the recipe', function (mixed $issues) {
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner()]], ['dinners' => [suggestedDinner()]]]);
    ReviewDinnerRecipes::fake([['issues' => $issues], ['issues' => []]]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertServiceUnavailable()->assertDontSee('Tomatpasta');
    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertOk();

    GenerateWeekDinners::assertPromptedTimes(2);
    ReviewDinnerRecipes::assertPromptedTimes(2);
    expect(AiRequest::orderBy('id')->pluck('status')->all())->toBe(['failed', 'ok']);
})->with(['unlisted' => [['unlisted_ingredient']], 'unused' => [['unused_ingredient']],
    'amount' => [['quantity_mismatch']], 'excluded synonym' => [['excluded_ingredient']],
    'malformed' => [null], 'unknown issue' => [['unknown']]]);

it('records generation and review costs together and reuses only reviewed results', function () {
    Http::preventStrayRequests();
    $answer = fn (array $data, float $cost) => ['id' => 'response', 'model' => 'test',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode($data)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120, 'cost' => $cost]];
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::sequence()
        ->push($answer(['dinners' => [suggestedDinner()]], 0.01))->push($answer(['issues' => []], 0.002))]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertOk();
    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertOk();

    Http::assertSentCount(2);
    expect((float) AiRequest::first()->cost)->toBe(0.012);
    expect(AiRequest::first()->input_tokens)->toBe(200);
    expect(AiRequest::first()->output_tokens)->toBe(40);
});

it('lets a guest preview complete dinners without creating household data', function () {
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner()]]]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput(['preferences' => 'Rask pasta']))
        ->assertOk()->assertJsonPath('data.dinners.0.ingredients.0.quantity', 200)
        ->assertJsonPath('data.dinners.0.notes', 'Kok pastaen. Varm tomatene og bland alt sammen.');

    GenerateWeekDinners::assertPrompted(fn ($prompt) => json_decode($prompt->prompt, true)['preferences'] === 'Rask pasta');
    foreach (['users', 'households', 'dinners', 'ingredients', 'dinner_plans', 'shopping_lists'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseHas('ai_daily_usage', ['feature' => 'week_planning', 'scope' => 'global', 'used' => 1]);
    expect(AiRequest::first()->request)->toBeNull();
    $this->assertDatabaseCount('api_requests', 0);
});

it('reuses only recipe references supplied by the caller without reading household catalogues', function () {
    Ingredient::factory()->create(['name' => 'Private ingredient']);
    $id = (string) Str::uuid();
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner(['existing_id' => $id, 'name' => 'Ignored generated name', 'ingredients' => [], 'notes' => null])]]]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput(['available' => [
        ['id' => $id, 'name' => 'My pasta', 'category' => 'vegetarian', 'ingredients' => ['Pasta']],
    ]]))->assertOk()->assertJsonPath('data.dinners.0.existing_id', $id)->assertJsonPath('data.dinners.0.name', 'My pasta');

    GenerateWeekDinners::assertPrompted(fn ($prompt) => ! str_contains($prompt->prompt, 'Private ingredient'));
});

it('returns 422 for invalid bounded input before contacting the provider', function (array $input, string $field) {
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake();

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput($input))
        ->assertUnprocessable()->assertJsonValidationErrors($field);

    GenerateWeekDinners::assertNeverPrompted();
    $this->assertDatabaseCount('ai_daily_usage', 0);
})->with([
    [['count' => 8], 'count'], [['count' => 0], 'count'], [['servings' => 0], 'servings'],
    [['servings' => 100], 'servings'], [['locale' => 'xx'], 'locale'],
    [['preferences' => str_repeat('x', 601)], 'preferences'],
    [['shortcuts' => ['unknown']], 'shortcuts.0'], [['exclude' => array_fill(0, 61, 'Soup')], 'exclude'],
    [['available' => array_fill(0, 21, [])], 'available'],
]);

it('returns 503 for incomplete or invalid dinners without exposing provider output', function (array $dinner) {
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake([['dinners' => [$dinner]]]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())
        ->assertServiceUnavailable()->assertJsonPath('code', 'unavailable')->assertDontSee('Tomatpasta');

    GenerateWeekDinners::assertPromptedTimes(1);
    $this->assertDatabaseHas('ai_daily_usage', ['feature' => 'week_planning', 'scope' => 'global', 'used' => 1]);
})->with([
    fn () => [suggestedDinner(['ingredients' => []])],
    fn () => [suggestedDinner(['notes' => null])],
    fn () => [suggestedDinner(['existing_id' => (string) Str::uuid()])],
    fn () => [suggestedDinner(['ingredients' => [['name' => 'Pasta', 'quantity' => -1, 'unit' => 'g']]])],
    fn () => [suggestedDinner(['ingredients' => [['name' => 'Pasta', 'quantity' => 1, 'unit' => 'unknown']]])],
    fn () => [suggestedDinner(['ingredients' => array_fill(0, 2, ['name' => 'Pasta', 'quantity' => 1, 'unit' => 'g'])])],
    fn () => [suggestedDinner(['ingredients' => [
        ['name' => 'Pasta', 'quantity' => 200, 'unit' => 'g'],
        ['name' => ' pasta ', 'quantity' => 1, 'unit' => 'kg'],
    ]])],
]);

it('returns 503 when a provider repeats meals or ignores explicit exclusions', function (array $overrides, array $dinners) {
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake([['dinners' => $dinners]]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput($overrides))->assertServiceUnavailable();

    GenerateWeekDinners::assertPromptedTimes(1);
})->with([
    fn () => [['count' => 2], [suggestedDinner(), suggestedDinner()]],
    fn () => [['exclude' => [' TOMATPASTA ']], [suggestedDinner()]],
    fn () => [['shortcuts' => ['vegetarian']], [suggestedDinner(['category' => 'meat'])]],
    fn () => [['count' => 2], [suggestedDinner()]],
]);

it('caches identical retries within the caller scope without spending another allowance', function () {
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner()]]]);
    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertOk();

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertOk();

    GenerateWeekDinners::assertPromptedTimes(1);
    $this->assertDatabaseHas('ai_daily_usage', ['feature' => 'week_planning', 'scope' => 'global', 'used' => 1]);
});

it('returns 429 when either daily budget is exhausted but can serve a cached retry', function (string $scope) {
    config(['assistance.week_planning.'.$scope => 1]);
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner()]]]);
    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertOk();

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput(['preferences' => 'Different']))
        ->assertTooManyRequests()->assertJsonPath('code', 'daily_limit')->assertHeader('Retry-After');
    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertOk();

    GenerateWeekDinners::assertPromptedTimes(1);
    expect(DB::table('ai_daily_usage')->sum('used'))->toBe(2);
})->with(['ip', 'global']);

it('returns 503 when disabled even for a cached result', function () {
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner()]]]);
    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertOk();
    config(['assistance.enabled' => false]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())->assertServiceUnavailable();

    GenerateWeekDinners::assertPromptedTimes(1);
});

it('returns 429 when the same caller already has a generation running', function () {
    ReviewDinnerRecipes::fake([['issues' => []]]);
    GenerateWeekDinners::fake();
    $key = 'planner:'.substr(hash_hmac('sha256', '127.0.0.1', (string) config('app.key')), 0, 48).':lock';
    $lock = Cache::lock($key, 70);
    $lock->get();
    try {
        $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())
            ->assertTooManyRequests()->assertJsonPath('code', 'busy');
        GenerateWeekDinners::assertNeverPrompted();
    } finally {
        $lock->release();
    }
});

it('returns 503 on provider failure without retrying or leaking diagnostics', function () {
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['error' => ['message' => 'private diagnostic']], 500)]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())
        ->assertServiceUnavailable()->assertDontSee('private diagnostic');

    Http::assertSentCount(1);
});

it('sends the complete recipe schema through the SDK using the configured server model', function () {
    ReviewDinnerRecipes::fake([['issues' => []]]);
    config(['assistance.suggestion_model' => 'google/gemini-3.1-flash-lite']);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
        'id' => 'test-response', 'model' => 'google/gemini-3.1-flash-lite',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant',
            'content' => json_encode(['dinners' => [suggestedDinner()]], JSON_THROW_ON_ERROR)], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
    ])]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput(['model' => 'untrusted-model']))
        ->assertOk()->assertJsonPath('data.dinners.0.name', 'Tomatpasta')
        ->assertJsonPath('data.dinners.0.ingredients.0.quantity', 200);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-server-key')
        && $request['model'] === 'google/gemini-3.1-flash-lite'
        && $request['max_tokens'] === 7000
        && $request['response_format']['type'] === 'json_schema'
        && isset($request['response_format']['json_schema']['schema']['properties']['dinners'])
        && ! isset($request['tools']));
});
