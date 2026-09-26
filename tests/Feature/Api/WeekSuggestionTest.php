<?php

use App\Actions\Ai\DinnerIdeas;
use App\Actions\Ai\GenerateWeekDinners;
use App\Actions\Ai\ReviewDinnerRecipes;
use App\Models\AiRequest;
use App\Models\AppSetting;
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
    'malformed' => [null], 'unknown issue' => [['unknown']],
    'too many issues' => [array_fill(0, 43, 'unused_ingredient')]]);

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
    Http::assertSent(fn ($request) => isset($request['response_format']['json_schema']['schema']['properties']['issues'])
        && ! isset($request['response_format']['json_schema']['schema']['properties']['issues']['maxItems']));
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
    [['count' => 2, 'days' => ['friday']], 'days'], [['days' => ['caturday']], 'days.0'],
    [['count' => 2, 'reuse' => 3], 'reuse'], [['shortcuts' => ['vegetarian', 'fish']], 'shortcuts'],
]);

it('sends weekdays, the reuse target, the season and matching dinner ideas to generation', function () {
    $this->travelTo(now()->setDate(2026, 9, 25));
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner(), suggestedDinner(['name' => 'Taco', 'category' => 'meat'])]]]);
    ReviewDinnerRecipes::fake([['issues' => []]]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput([
        'count' => 2, 'days' => ['thursday', 'friday'], 'reuse' => 0, 'shortcuts' => ['kids', 'weekend'],
        'exclude' => ['Lasagne'],
    ]))->assertOk();

    GenerateWeekDinners::assertPrompted(function ($prompt) {
        $input = json_decode($prompt->prompt, true);

        return $input['days'] === ['thursday', 'friday'] && $input['reuse'] === 0 && $input['month'] === 9
            && count($input['ideas']) === 14 && ! in_array('Lasagne', $input['ideas'], true)
            && str_contains($prompt->agent->instructions(), 'hverdagsmiddager');
    });
});

it('samples familiar dinner ideas that respect vegetarian, season, exclusions and language', function () {
    $ideas = new DinnerIdeas;

    $vegetarian = $ideas->sample('nb', ['vegetarian'], ['Risgrøt'], 9, 40);
    expect($vegetarian)->toContain('Tomatsuppe med egg og makaroni', 'Linsesuppe')
        ->not->toContain('Taco', 'Fiskegrateng', 'Risgrøt', 'Hjemmelaget pizza');
    expect($ideas->sample('nb', [], [], 9, 200))->toContain('Fårikål');
    expect($ideas->sample('nb', [], [], 6, 200))->not->toContain('Fårikål')->toContain('Grillet kylling med potetsalat');
    expect($ideas->sample('en', [], ['taco'], 3, 200))->toContain('Fish gratin')->not->toContain('Fiskegrateng', 'Tacos');

    // Two thirds of the handful come from ideas matching the chosen shortcuts.
    $fish = $ideas->sample('nb', ['fish'], [], 3, 9);
    expect($fish)->toHaveCount(9)
        ->and(count(preg_grep('/fisk|laks|torsk|sei|scampi|bacalao/iu', $fish)))->toBeGreaterThanOrEqual(6);
});

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
    fn () => [suggestedDinner(['ingredients' => array_map(fn (int $number) => [
        'name' => 'Ingredient '.$number, 'quantity' => 1, 'unit' => 'g',
    ], range(1, 21))])],
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

it('uses the saved admin AI switch for week planning even when the environment differs', function (bool $enabled) {
    config(['assistance.enabled' => ! $enabled]);
    AppSetting::set('ai.enabled', $enabled);
    GenerateWeekDinners::fake([['dinners' => [suggestedDinner()]]])->preventStrayPrompts();
    ReviewDinnerRecipes::fake([['issues' => []]])->preventStrayPrompts();

    $response = $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput());

    if ($enabled) {
        $response->assertOk()->assertJsonPath('data.dinners.0.name', 'Tomatpasta');
        GenerateWeekDinners::assertPromptedTimes(1);
        ReviewDinnerRecipes::assertPromptedTimes(1);
    } else {
        $response->assertServiceUnavailable()->assertJsonPath('code', 'unavailable');
        GenerateWeekDinners::assertNeverPrompted();
        ReviewDinnerRecipes::assertNeverPrompted();
        $this->assertDatabaseCount('ai_daily_usage', 0);
    }
})->with([true, false]);

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

it('records the failed provider stage and HTTP diagnostic with secrets and guest data redacted', function (string $stage) {
    Http::preventStrayRequests();
    $responses = Http::sequence();
    if ($stage === 'review') {
        $responses->push([
            'id' => 'generation', 'model' => 'test',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant',
                'content' => json_encode(['dinners' => [suggestedDinner()]])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200, 'total_tokens' => 300],
        ]);
    }
    $responses->push(['error' => [
        'message' => 'Invalid response_format. test-server-key Bearer another-secret. Private preference: Bønner til middag.',
        'metadata' => ['raw' => 'This raw provider body must not be saved'],
    ]], 400);
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => $responses]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput(['preferences' => 'Bønner til middag.']))
        ->assertServiceUnavailable()->assertJsonPath('code', 'unavailable')->assertDontSee('response_format');

    $record = AiRequest::query()->sole();
    expect($record->error)->toContain($stage.'; HTTP 400; Invalid response_format.', '[redacted]')
        ->not->toContain('test-server-key', 'another-secret', 'Bønner til middag.', 'raw provider body');
    expect($record->request)->toBeNull();
    expect($record->response)->toBeNull();
    Http::assertSentCount($stage === 'review' ? 2 : 1);
})->with(['generation', 'review']);

it('records HTTP status without storing non-JSON provider error pages', function () {
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response('<html>Private proxy diagnostic</html>', 502)]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput())
        ->assertServiceUnavailable()->assertDontSee('Private proxy diagnostic');

    expect(AiRequest::query()->sole()->error)->toBe('Laravel\\Ai\\Exceptions\\ProviderOverloadedException: generation; HTTP 502');
    Http::assertSentCount(1);
});

it('extracts the upstream error message without retaining the rest of its raw payload', function () {
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response(['error' => [
        'message' => 'Provider returned error',
        'metadata' => ['error_type' => 'invalid_request', 'provider_code' => 'INVALID_ARGUMENT', 'raw' => json_encode([
            'error' => ['message' => 'Unsupported schema for test-server-key: private preference', 'details' => 'private upstream body'],
        ])],
    ]], 400)]);

    $this->postJson('/api/v1/ai/plan-week', weekSuggestionInput(['preferences' => 'private preference']))
        ->assertServiceUnavailable();

    expect(AiRequest::query()->sole()->error)->toContain('HTTP 400', 'error_type=invalid_request',
        'provider_code=INVALID_ARGUMENT', 'Unsupported schema for [redacted]: [redacted]')
        ->not->toContain('test-server-key', 'private preference', 'private upstream body');
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
        && isset($request['response_format']['json_schema']['schema']['properties']['dinners']['items']['properties']['ingredients'])
        && ! isset($request['response_format']['json_schema']['schema']['properties']['dinners']['minItems'])
        && ! isset($request['response_format']['json_schema']['schema']['properties']['dinners']['maxItems'])
        && ! isset($request['response_format']['json_schema']['schema']['properties']['dinners']['items']['properties']['ingredients']['maxItems'])
        && ! isset($request['tools']));
});
