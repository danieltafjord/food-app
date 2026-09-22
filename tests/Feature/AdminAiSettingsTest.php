<?php

use App\Actions\Ai\AiConfiguration;
use App\Actions\Ai\AiUsage;
use App\Actions\Ai\ListOpenRouterModels;
use App\Actions\Ai\SuggestDinnerIngredients;
use App\Enums\ReasoningEffort;
use App\Models\AdminAction;
use App\Models\AiRequest;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;
use Inertia\Middleware;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Enums\Lab;
use Laravel\Passport\Passport;

beforeEach(function () {
    // Http::preventStrayRequests() would otherwise block the SSR render call.
    config(['inertia.ssr.enabled' => false]);
    config(['assistance.classification_model' => 'typesafe/jev-1.13', 'assistance.suggestion_model' => 'google/gemini-3.5-flash-lite', 'assistance.suggestion_reasoning' => 'minimal']);
});

test('the AI settings page shows the configured defaults and the OpenRouter catalogue', function () {
    Http::preventStrayRequests();
    Http::fake([ListOpenRouterModels::URL => Http::response(['data' => [
        ['id' => 'openai/gpt-5-mini', 'name' => 'OpenAI: GPT-5 mini', 'context_length' => 400000, 'pricing' => ['prompt' => '0.00000025', 'completion' => '0.000002'], 'supported_parameters' => ['reasoning', 'temperature']],
        ['id' => 'google/gemini-3.5-flash-lite', 'name' => 'Google: Gemini 3.5 Flash Lite', 'context_length' => 1048576, 'pricing' => ['prompt' => '0.0000001', 'completion' => '0.0000004'], 'supported_parameters' => ['temperature']],
        ['id' => 42, 'name' => 'broken entry'],
    ]])]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/AiSettings')
            ->has('features', 2)
            ->where('features.0.key', 'categorization')
            ->where('features.0.model', 'typesafe/jev-1.13')
            ->where('features.0.supports_reasoning', false)
            ->where('features.1.key', 'suggestions')
            ->where('features.1.model', 'google/gemini-3.5-flash-lite')
            ->where('features.1.reasoning', 'minimal')
            ->where('reasoningEfforts', ReasoningEffort::values())
            ->has('limits', 2)
            ->where('limits.1.feature', 'suggestions')
            ->where('limits.1.user', config('assistance.limits.suggestions.user'))
            ->where('limits.1.defaults.global', config('assistance.limits.suggestions.global'))
            ->has('recentActions', 0)
            ->where('usage.features.suggestions.global_used', 0)
            ->where('usage.features.suggestions.sample_size', 0)
            ->has('usage.resets_at')
        );

    // Deferred props load on a follow-up request.
    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai.edit'), ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'admin/AiSettings', 'X-Inertia-Partial-Data' => 'models,catalogue', 'X-Inertia-Version' => app(Middleware::class)->version(request()) ?? ''])
        ->assertOk()
        ->assertJsonPath('props.catalogue.failed', false)
        ->assertJsonCount(2, 'props.models')
        ->assertJsonPath('props.models.0.id', 'google/gemini-3.5-flash-lite')
        ->assertJsonPath('props.models.0.supports_reasoning', false)
        ->assertJsonPath('props.models.1.id', 'openai/gpt-5-mini')
        ->assertJsonPath('props.models.1.prompt_price', 0.25)
        ->assertJsonPath('props.models.1.completion_price', 2)
        ->assertJsonPath('props.models.1.supports_reasoning', true);

    Http::assertSentCount(1);
});

test('the catalogue is empty rather than failing when OpenRouter is unreachable', function () {
    Http::preventStrayRequests();
    Http::fake([ListOpenRouterModels::URL => Http::response('nope', 500)]);

    expect(app(ListOpenRouterModels::class)->handle())->toBe([])
        ->and(app(ListOpenRouterModels::class)->status()['failed'])->toBeTrue();
});

test('an admin can refresh the cached catalogue', function () {
    Http::preventStrayRequests();
    Http::fake([ListOpenRouterModels::URL => Http::response(['data' => [['id' => 'openai/gpt-5-mini', 'name' => 'OpenAI: GPT-5 mini']]])]);
    app(ListOpenRouterModels::class)->handle();
    app(ListOpenRouterModels::class)->handle();
    Http::assertSentCount(1);

    $this->actingAs(User::factory()->admin()->create())
        ->from(route('admin.ai.edit'))
        ->post(route('admin.ai.catalogue.refresh'))
        ->assertRedirect(route('admin.ai.edit'));

    app(ListOpenRouterModels::class)->handle();
    Http::assertSentCount(2);
});

test('the usage overview shows today\'s budget use and average tokens', function () {
    [$user, $household] = ownerWithHousehold();
    [$other, $otherHousehold] = ownerWithHousehold();
    $usage = app(AiUsage::class);
    $usage->reserve($user, $household, 'suggestions');
    $usage->reserve($user, $household, 'suggestions');
    $usage->reserve($other, $otherHousehold, 'suggestions');
    AiRequest::factory()->for($user)->for($household)->create(['feature' => 'suggestions', 'input_tokens' => 100, 'output_tokens' => 20]);
    AiRequest::factory()->for($user)->for($household)->create(['feature' => 'suggestions', 'input_tokens' => 300, 'output_tokens' => 40]);
    AiRequest::factory()->failed()->for($user)->for($household)->create(['feature' => 'suggestions', 'input_tokens' => 900, 'output_tokens' => 900]);

    $this->actingAs(User::factory()->admin()->create())
        ->get(route('admin.ai.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('usage.features.suggestions.global_used', 3)
            ->where('usage.features.suggestions.top_user_used', 2)
            ->where('usage.features.suggestions.top_household_used', 2)
            ->where('usage.features.suggestions.avg_input_tokens', 200)
            ->where('usage.features.suggestions.avg_output_tokens', 30)
            ->where('usage.features.suggestions.sample_size', 2)
            ->where('usage.features.categorization.global_used', 0)
        );
});

test('an admin can test categorization with unsaved settings', function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-server-key']);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response([
        'answers' => ['category' => ['type' => 'choice', 'choice' => 'produce', 'confidence' => 0.97]],
        'usage' => ['input_tokens' => 476, 'output_tokens' => 70, 'cost' => 0.000019992],
    ])]);
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->postJson(route('admin.ai.test'), ['feature' => 'categorization', 'model' => 'typesafe/jev-2.0', 'sample' => 'Pak choi'])
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.category', 'produce')
        ->assertJsonPath('input_tokens', 476)
        ->assertJsonPath('raw.answers.category.choice', 'produce')
        ->assertJsonPath('error', null);

    Http::assertSent(fn ($request) => $request['model'] === 'typesafe/jev-2.0' && $request['state']['ingredient'] === 'pak choi');
    $this->assertDatabaseHas('ai_requests', ['user_id' => $admin->id, 'feature' => 'categorization', 'model' => 'typesafe/jev-2.0', 'status' => 'ok']);
    expect(AiRequest::query()->sole()->request)->toMatchArray(['name' => 'pak choi', 'test' => true]);
    expect(app(AiConfiguration::class)->model('categorization'))->toBe('typesafe/jev-1.13');
});

test('an admin can test suggestions with an unsaved reasoning effort', function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-server-key']);
    SuggestDinnerIngredients::fake([['ingredients' => ['Salsa', 'Cheese']]]);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('admin.ai.test'), ['feature' => 'suggestions', 'model' => 'openai/gpt-5-mini', 'reasoning' => 'high', 'sample' => 'Tacos'])
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.ingredients', ['Salsa', 'Cheese']);

    SuggestDinnerIngredients::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Tacos'));
    $this->assertDatabaseHas('ai_requests', ['feature' => 'suggestions', 'model' => 'openai/gpt-5-mini', 'status' => 'ok']);
    expect(AiRequest::query()->sole()->request['reasoning'])->toBe('high');
});

test('a failed test reports the provider error without saving anything', function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-server-key']);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(['error' => 'unknown model'], 400)]);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('admin.ai.test'), ['feature' => 'categorization', 'model' => 'typesafe/nope'])
        ->assertOk()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('data', null);

    expect(AiRequest::query()->sole())->status->toBe('failed')->error->toContain('unknown model');
    $this->assertDatabaseCount('app_settings', 0);
});

test('tests are refused when server-side AI is off and for regular users', function () {
    config(['assistance.enabled' => false]);
    Http::preventStrayRequests();

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('admin.ai.test'), ['feature' => 'categorization', 'model' => 'typesafe/jev-1.13'])
        ->assertUnprocessable();

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('admin.ai.test'), ['feature' => 'categorization', 'model' => 'not a model'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['model']);

    $this->actingAs(User::factory()->create())
        ->postJson(route('admin.ai.test'), ['feature' => 'categorization', 'model' => 'typesafe/jev-1.13'])
        ->assertForbidden();

    $this->assertDatabaseCount('ai_requests', 0);
});

test('an admin can change a feature model and reasoning effort', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.ai.update'), ['feature' => 'suggestions', 'model' => ' openai/gpt-5-mini ', 'reasoning' => 'high'])
        ->assertRedirect(route('admin.ai.edit'))
        ->assertSessionHasNoErrors();

    $configuration = app(AiConfiguration::class);
    expect($configuration->model('suggestions'))->toBe('openai/gpt-5-mini')
        ->and($configuration->reasoningEffort('suggestions'))->toBe(ReasoningEffort::High)
        ->and($configuration->model('categorization'))->toBe('typesafe/jev-1.13');

    expect(app(SuggestDinnerIngredients::class)->providerOptions(Lab::OpenRouter)['reasoning'])->toBe(['effort' => 'high']);
});

test('model changes are written to the audit trail once', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->patch(route('admin.ai.update'), ['feature' => 'suggestions', 'model' => 'openai/gpt-5-mini', 'reasoning' => 'high'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->patch(route('admin.ai.update'), ['feature' => 'suggestions', 'model' => 'openai/gpt-5-mini', 'reasoning' => 'high'])->assertSessionHasNoErrors();

    $entry = AdminAction::query()->sole();
    expect($entry->action)->toBe(AdminAction::AI_MODEL_UPDATED)
        ->and($entry->subject_label)->toBe('Dinner ingredient suggestions')
        ->and($entry->changes)->toBe([
            'model' => ['from' => 'google/gemini-3.5-flash-lite', 'to' => 'openai/gpt-5-mini'],
            'reasoning' => ['from' => 'minimal', 'to' => 'high'],
        ]);

    $this->actingAs($admin)->get(route('admin.ai.edit'))
        ->assertInertia(fn (Assert $page) => $page->has('recentActions', 1)->where('recentActions.0.action', AdminAction::AI_MODEL_UPDATED));
});

test('an admin can change the daily budgets and they take effect immediately', function () {
    $admin = User::factory()->admin()->create();
    [$user, $household] = ownerWithHousehold();

    $this->actingAs($admin)
        ->patch(route('admin.ai.limits.update'), ['feature' => 'suggestions', 'user' => 2, 'household' => 5, 'global' => 50])
        ->assertRedirect(route('admin.ai.edit'))
        ->assertSessionHasNoErrors();

    $configuration = app(AiConfiguration::class);
    expect($configuration->limit('suggestions', 'user'))->toBe(2)
        ->and($configuration->limit('suggestions', 'household'))->toBe(5)
        ->and($configuration->limit('suggestions', 'global'))->toBe(50)
        ->and($configuration->limit('categorization', 'user'))->toBe((int) config('assistance.limits.categorization.user'));

    $usage = app(AiUsage::class);
    expect($usage->status($user, $household)['suggestions']['remaining'])->toBe(2);
    $usage->reserve($user, $household, 'suggestions');
    $usage->reserve($user, $household, 'suggestions');
    expect(fn () => $usage->reserve($user, $household, 'suggestions'))->toThrow(HttpResponseException::class);

    $entry = AdminAction::query()->sole();
    expect($entry->action)->toBe(AdminAction::AI_LIMITS_UPDATED)
        ->and($entry->changes['user'])->toBe(['from' => (int) config('assistance.limits.suggestions.user'), 'to' => 2]);
});

test('invalid budgets are rejected', function (array $payload, array $errors) {
    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.ai.limits.update'), ['feature' => 'suggestions', ...$payload])
        ->assertSessionHasErrors($errors);

    $this->assertDatabaseCount('app_settings', 0);
})->with([
    'negative' => [['user' => -1, 'household' => 5, 'global' => 50], ['user']],
    'household below user' => [['user' => 10, 'household' => 5, 'global' => 50], ['household']],
    'global below household' => [['user' => 1, 'household' => 5, 'global' => 4], ['global']],
    'not a number' => [['user' => 'lots', 'household' => 5, 'global' => 50], ['user']],
]);

test('regular users cannot change budgets', function () {
    $this->actingAs(User::factory()->create())
        ->patch(route('admin.ai.limits.update'), ['feature' => 'suggestions', 'user' => 1, 'household' => 1, 'global' => 1])
        ->assertForbidden();
});

test('a null reasoning effort falls back to the provider default', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.ai.update'), ['feature' => 'suggestions', 'model' => 'openai/gpt-5-mini', 'reasoning' => null])
        ->assertSessionHasNoErrors();

    expect(app(AiConfiguration::class)->reasoningEffort('suggestions'))->toBeNull()
        ->and(app(SuggestDinnerIngredients::class)->providerOptions(Lab::OpenRouter))->not->toHaveKey('reasoning');
});

test('reasoning is ignored for features that do not support it', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.ai.update'), ['feature' => 'categorization', 'model' => 'jev-latest', 'reasoning' => 'high'])
        ->assertSessionHasNoErrors();

    expect(app(AiConfiguration::class)->model('categorization'))->toBe('jev-latest')
        ->and(app(AiConfiguration::class)->reasoningEffort('categorization'))->toBeNull()
        ->and(AppSetting::get('ai.categorization.reasoning'))->toBeNull();
});

test('invalid AI settings are rejected', function (array $payload, array $errors) {
    $this->actingAs(User::factory()->admin()->create())
        ->patch(route('admin.ai.update'), $payload)
        ->assertSessionHasErrors($errors);

    $this->assertDatabaseCount('app_settings', 0);
})->with([
    'unknown feature' => [['feature' => 'chat', 'model' => 'openai/gpt-5-mini'], ['feature']],
    'missing model' => [['feature' => 'suggestions', 'model' => ''], ['model']],
    'model with spaces' => [['feature' => 'suggestions', 'model' => 'not a model'], ['model']],
    'unknown effort' => [['feature' => 'suggestions', 'model' => 'openai/gpt-5-mini', 'reasoning' => 'ultra'], ['reasoning']],
]);

test('settings changes are picked up by the next AI request and recorded for analytics', function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-server-key']);
    [$user, $household] = ownerWithHousehold();
    $user->forceFill(['ai_categorization_enabled' => true])->save();
    app(AiConfiguration::class)->update('categorization', 'typesafe/jev-2.0', null);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response([
        'answers' => ['category' => ['type' => 'choice', 'choice' => 'produce', 'confidence' => 0.97]],
        'usage' => ['input_tokens' => 476, 'output_tokens' => 70, 'cost' => 0.000019992],
    ])]);
    Passport::actingAs($user);

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['model'] === 'typesafe/jev-2.0');
    $this->assertDatabaseHas('ai_requests', ['user_id' => $user->id, 'household_id' => $household->id, 'feature' => 'categorization', 'model' => 'typesafe/jev-2.0', 'status' => 'ok', 'input_tokens' => 476, 'output_tokens' => 70]);
    $this->assertDatabaseHas('ai_requests', ['feature' => 'categorization', 'status' => 'cached']);
    expect(AiRequest::query()->where('status', 'ok')->value('cost'))->toEqualWithDelta(0.000019992, 1e-8);
});

test('admin suggestions apply the same existing ingredient and duplicate filtering as production', function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-server-key']);
    SuggestDinnerIngredients::fake([['ingredients' => ['TORTILLAS', ' Salsa ', 'salsa']]]);

    $this->actingAs(User::factory()->admin()->create())
        ->postJson(route('admin.ai.test'), ['feature' => 'suggestions', 'model' => 'test/model', 'sample' => 'Tacos'])
        ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('data.ingredients', ['Salsa']);

    SuggestDinnerIngredients::assertPromptedTimes(1);
    expect(AiRequest::query()->sole()->response['data']['ingredients'])->toBe(['Salsa']);
});
