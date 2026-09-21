<?php

use App\Actions\Ai\RunAiRequest;
use App\Actions\Ai\SuggestDinnerIngredients;
use App\Actions\ApiTokens\CreateApiToken;
use App\Enums\HouseholdRole;
use App\Models\Ingredient;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

beforeEach(function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-server-key']);
});

function enableAi(User $user): void
{
    $user->forceFill(['ai_categorization_enabled' => true, 'ai_suggestions_enabled' => true])->save();
    Passport::actingAs($user);
}

function jevAnswer(string $category = 'produce', float $confidence = 0.98): array
{
    return ['answers' => ['category' => ['type' => 'choice', 'choice' => $category, 'confidence' => $confidence]]];
}

it('returns 401 for unauthenticated AI requests', function () {
    Http::preventStrayRequests();

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertUnauthorized();
    $this->getJson('/api/v1/ai/settings')->assertUnauthorized();
    Http::assertNothingSent();
});

it('defaults both AI features to off and persists preferences for the current user', function () {
    [$user] = ownerWithHousehold();
    $other = User::factory()->create();
    Passport::actingAs($user);

    $this->getJson('/api/v1/ai/settings')->assertOk()
        ->assertJsonPath('data.categorization_enabled', false)
        ->assertJsonPath('data.suggestions_enabled', false)
        ->assertJsonPath('data.usage.categorization.user.limit', 100)
        ->assertJsonPath('data.usage.suggestions.user.limit', 20);
    $this->patchJson('/api/v1/ai/settings', ['categorization_enabled' => true, 'suggestions_enabled' => false])
        ->assertOk()->assertJsonPath('data.categorization_enabled', true)->assertJsonPath('data.suggestions_enabled', false);

    expect($user->refresh()->ai_categorization_enabled)->toBeTrue();
    expect($other->refresh()->ai_categorization_enabled)->toBeFalse();
});

it('validates AI preferences without changing them', function () {
    [$user] = ownerWithHousehold();
    Passport::actingAs($user);

    $this->patchJson('/api/v1/ai/settings', ['categorization_enabled' => 'yes'])
        ->assertUnprocessable()->assertJsonValidationErrors(['categorization_enabled', 'suggestions_enabled']);
    expect($user->refresh()->ai_categorization_enabled)->toBeFalse();
});

it('returns 403 when the requested feature is disabled', function (string $endpoint, array $payload) {
    [$user] = ownerWithHousehold();
    Passport::actingAs($user);
    Http::preventStrayRequests();

    $this->postJson('/api/v1/ai/'.$endpoint, $payload)->assertForbidden()->assertJsonPath('code', 'disabled');
    Http::assertNothingSent();
    $this->assertDatabaseCount('ai_daily_usage', 0);
})->with([
    ['categorize', ['name' => 'Pak choi', 'locale' => 'nb']],
    ['suggest', ['name' => 'Tacos', 'ingredients' => [], 'locale' => 'nb']],
]);

it('returns 403 for unverified accounts before calling a provider', function () {
    [$user] = ownerWithHousehold();
    $user->forceFill(['email_verified_at' => null])->save();
    enableAi($user);
    Http::preventStrayRequests();

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])
        ->assertForbidden()->assertJsonPath('code', 'verification_required');
    Http::assertNothingSent();
});

it('returns 409 when the active household membership was revoked', function () {
    [$user, $household] = ownerWithHousehold();
    enableAi($user);
    $household->members()->detach($user);
    Http::preventStrayRequests();

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertConflict();
    Http::assertNothingSent();
});

it('returns 403 for integration tokens even when they can write', function () {
    [$user, $household] = ownerWithHousehold();
    $token = app(CreateApiToken::class)->handle($user, $household, 'Integration', true)->accessToken;
    Http::preventStrayRequests();

    $this->withToken($token)->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertForbidden();
    Http::assertNothingSent();
});

it('returns 503 without spending quota when server AI is unavailable', function (array $configuration) {
    [$user] = ownerWithHousehold();
    enableAi($user);
    config($configuration);
    Http::preventStrayRequests();

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertServiceUnavailable();
    Http::assertNothingSent();
    $this->assertDatabaseCount('ai_daily_usage', 0);
})->with([
    [['assistance.enabled' => false]],
    [['ai.providers.openrouter.key' => '']],
]);

it('classifies through OpenRouter with the server key and caches equivalent names', function () {
    [$user, $household] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();
    $level = DB::transactionLevel();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => function ($request) use ($level) {
        expect(DB::transactionLevel())->toBe($level);

        return Http::response(jevAnswer());
    }]);

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb', 'model' => 'expensive-model'])
        ->assertOk()->assertJsonPath('data.category', 'produce');
    $this->postJson('/api/v1/ai/categorize', ['name' => ' PAK CHOI ', 'locale' => 'nb'])->assertOk();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-server-key')
        && $request['model'] === 'typesafe/jev-1.13'
        && count($request['questions']['category']['criteria']) === 18
        && $request['state'] === ['ingredient' => 'pak choi', 'locale' => 'nb']);
    $this->assertDatabaseHas('ai_daily_usage', ['scope' => 'user:'.$user->id, 'feature' => 'categorization', 'used' => 1]);
    expect($household->ingredients()->count())->toBe(0);
});

it('does not return cached data after opt-out', function () {
    [$user] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer())]);
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();
    $this->patchJson('/api/v1/ai/settings', ['categorization_enabled' => false, 'suggestions_enabled' => true])->assertOk();

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertForbidden();
    Http::assertSentCount(1);
});

it('accepts pet and baby categories from the classifier', function (string $name, string $category) {
    [$user] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer($category))]);

    $this->postJson('/api/v1/ai/categorize', ['name' => $name, 'locale' => 'en'])
        ->assertOk()->assertJsonPath('data.category', $category);

    Http::assertSent(fn ($request) => isset($request['questions']['category']['criteria'][$category]));
})->with([['Cat litter', 'pets'], ['Infant formula', 'baby']]);

it('leaves uncertain ingredients uncategorized', function (string $choice, float $confidence) {
    [$user] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer($choice, $confidence))]);

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Unknown product', 'locale' => 'en'])
        ->assertOk()->assertJsonPath('data.category', null);
    Http::assertSentCount(1);
})->with([['pantry', 0.5], ['other', 0.99]]);

it('returns 503 for malformed provider data and retains the reserved budget', function () {
    [$user] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer('invented-category'))]);

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertServiceUnavailable();
    Http::assertSentCount(1);
    $this->assertDatabaseHas('ai_daily_usage', ['scope' => 'user:'.$user->id, 'used' => 1]);
});

it('returns 503 for provider failures without automatic billable retries', function () {
    [$user] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(['error' => 'secret provider diagnostic'], 500)]);

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])
        ->assertServiceUnavailable()->assertDontSee('secret provider diagnostic');
    Http::assertSentCount(1);
    $this->assertDatabaseHas('ai_daily_usage', ['scope' => 'user:'.$user->id, 'used' => 1]);
});

it('enforces each daily budget atomically and still serves cached results', function (string $scope) {
    [$user] = ownerWithHousehold();
    enableAi($user);
    config(['assistance.limits.categorization.'.$scope => 1]);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer())]);
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Galangal', 'locale' => 'nb'])
        ->assertTooManyRequests()->assertJsonPath('code', 'daily_limit')->assertHeader('Retry-After');
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();
    expect(DB::table('ai_daily_usage')->sum('used'))->toBe(3);
    Http::assertSentCount(1);
})->with(['user', 'household', 'global']);

it('shares household budgets between members', function () {
    [$user, $household] = ownerWithHousehold();
    enableAi($user);
    config(['assistance.limits.categorization.household' => 1]);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer())]);
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();
    $member = User::factory()->create(['current_household_id' => $household->id]);
    $household->members()->attach($member, ['role' => HouseholdRole::Member]);
    enableAi($member);

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Galangal', 'locale' => 'nb'])->assertTooManyRequests();
    Http::assertSentCount(1);
    $this->assertDatabaseMissing('ai_daily_usage', ['scope' => 'user:'.$member->id]);
});

it('keeps a users daily budget when switching household and resets at midnight UTC', function () {
    $this->travelTo(now('UTC')->setTime(23, 59, 0));
    [$user] = ownerWithHousehold();
    [, $otherHousehold] = ownerWithHousehold();
    enableAi($user);
    config(['assistance.limits.categorization.user' => 1]);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer())]);
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();
    $otherHousehold->members()->attach($user, ['role' => HouseholdRole::Member]);
    $user->update(['current_household_id' => $otherHousehold->id]);

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Galangal', 'locale' => 'nb'])->assertTooManyRequests();
    $this->travel(2)->minutes();
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Galangal', 'locale' => 'nb'])->assertOk();
    Http::assertSentCount(2);
});

it('does not reset paid usage when the result cache is flushed', function () {
    [$user] = ownerWithHousehold();
    enableAi($user);
    config(['assistance.limits.categorization.user' => 1]);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer())]);
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();
    Cache::flush();

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertTooManyRequests();
    Http::assertSentCount(1);
});

it('throttles request bursts including cache hits', function () {
    [$user] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer())]);
    for ($i = 0; $i < 12; $i++) {
        $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();
    }

    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertTooManyRequests();
    Http::assertSentCount(1);
});

it('rejects oversized and unsupported classification inputs before provider usage', function (array $payload, string $field) {
    [$user] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();

    $this->postJson('/api/v1/ai/categorize', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    Http::assertNothingSent();
    $this->assertDatabaseCount('ai_daily_usage', 0);
})->with([
    [['name' => str_repeat('x', 121), 'locale' => 'nb'], 'name'],
    [['name' => 'Pak choi', 'locale' => 'xx'], 'locale'],
    [['name' => ' ', 'locale' => 'nb'], 'name'],
]);

it('suggests through the SDK using only the active household catalogue and filters duplicates', function () {
    [$user, $household] = ownerWithHousehold();
    enableAi($user);
    Ingredient::factory()->for($household)->create(['name' => 'Rødløk']);
    Ingredient::factory()->create(['name' => 'Foreign secret ingredient']);
    SuggestDinnerIngredients::fake([['ingredients' => ['Tortilla', 'Rødløk', 'rødløk']]]);

    $this->postJson('/api/v1/ai/suggest', ['name' => 'Tacos', 'ingredients' => ['Tortilla'], 'locale' => 'nb'])
        ->assertOk()->assertJsonPath('data.ingredients', ['Rødløk']);
    SuggestDinnerIngredients::assertPrompted(function ($prompt) {
        $context = json_decode($prompt->prompt, true);

        return $context['catalogue'] === ['Rødløk'] && $context['locale'] === 'nb';
    });
    $this->assertDatabaseHas('ai_daily_usage', ['scope' => 'user:'.$user->id, 'feature' => 'suggestions', 'used' => 1]);
});

it('enforces suggestion budgets separately from classification', function () {
    [$user] = ownerWithHousehold();
    enableAi($user);
    config(['assistance.limits.suggestions.user' => 1]);
    SuggestDinnerIngredients::fake([['ingredients' => ['Onion']]]);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/systemone' => Http::response(jevAnswer())]);
    $this->postJson('/api/v1/ai/suggest', ['name' => 'Tacos', 'ingredients' => [], 'locale' => 'en'])->assertOk();

    $this->postJson('/api/v1/ai/suggest', ['name' => 'Soup', 'ingredients' => [], 'locale' => 'en'])->assertTooManyRequests();
    $this->postJson('/api/v1/ai/categorize', ['name' => 'Pak choi', 'locale' => 'nb'])->assertOk();
    SuggestDinnerIngredients::assertPromptedTimes(1);
    Http::assertSentCount(1);
});

it('rejects malformed suggestions without exposing generated text', function () {
    [$user] = ownerWithHousehold();
    enableAi($user);
    SuggestDinnerIngredients::fake([['ingredients' => ['<script>bad output</script>']]]);

    $this->postJson('/api/v1/ai/suggest', ['name' => 'Tacos', 'ingredients' => [], 'locale' => 'en'])
        ->assertServiceUnavailable()->assertDontSee('bad output');
    SuggestDinnerIngredients::assertPromptedTimes(1);
});

it('validates bounded suggestion input before inference', function () {
    [$user] = ownerWithHousehold();
    enableAi($user);
    SuggestDinnerIngredients::fake();

    $this->postJson('/api/v1/ai/suggest', ['name' => 'Tacos', 'ingredients' => array_fill(0, 41, 'Onion'), 'locale' => 'en'])
        ->assertUnprocessable()->assertJsonValidationErrors('ingredients');
    SuggestDinnerIngredients::assertNeverPrompted();
});

it('syncs manual category provenance including an explicit cleared category', function () {
    [$user, $household] = ownerWithHousehold();
    Passport::actingAs($user);
    $id = (string) Str::uuid();

    $this->postJson('/api/v1/sync', ['household_id' => $household->id, 'cursor' => null, 'changes' => [
        'ingredients' => [[
            'id' => $id, 'name' => 'Custom food', 'category' => null, 'category_source' => 'user',
            'default_unit' => null, 'created_at' => now()->toISOString(), 'updated_at' => now()->toISOString(),
        ]],
    ]])->assertOk()->assertJsonPath('changes.ingredients.0.category_source', 'user');
    $this->assertDatabaseHas('ingredients', ['uuid' => $id, 'category' => null, 'category_source' => 'user']);
});

it('sends structured suggestions through the SDK with bounded output and a fixed server model', function () {
    config(['assistance.suggestion_model' => 'google/gemini-3.1-flash-lite']);
    [$user] = ownerWithHousehold();
    enableAi($user);
    Http::preventStrayRequests();
    Http::fake(['https://openrouter.ai/api/v1/chat/completions' => Http::response([
        'id' => 'test-response', 'model' => 'google/gemini-3.1-flash-lite',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => '{"ingredients":["Onion"]}'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'total_tokens' => 110],
    ])]);

    $this->postJson('/api/v1/ai/suggest', ['name' => 'Tacos', 'ingredients' => [], 'locale' => 'en', 'model' => 'attacker-model'])
        ->assertOk()->assertJsonPath('data.ingredients', ['Onion']);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-server-key')
        && $request['model'] === 'google/gemini-3.1-flash-lite'
        && $request['max_tokens'] === 512
        && $request['response_format']['type'] === 'json_schema'
        && ! isset($request['tools']));
});

it('coalesces identical in-flight requests without reserving twice', function () {
    [$user, $household] = ownerWithHousehold();
    enableAi($user);
    $runner = app(RunAiRequest::class);
    $calls = 0;
    $context = ['name' => 'Pak choi', 'locale' => 'nb'];

    $result = $runner->handle($user, $household, 'categorization', $context, function () use ($runner, $user, $household, $context, &$calls) {
        $calls++;
        try {
            $runner->handle($user, $household, 'categorization', $context, function () use (&$calls) {
                $calls++;

                return ['category' => 'meat'];
            });
            test()->fail('A duplicate request should not run while inference is in flight.');
        } catch (HttpResponseException $exception) {
            expect($exception->getResponse()->getStatusCode())->toBe(429);
        }

        return ['category' => 'produce'];
    });

    expect($result)->toBe(['category' => 'produce']);
    expect($calls)->toBe(1);
    $this->assertDatabaseHas('ai_daily_usage', ['scope' => 'user:'.$user->id, 'used' => 1]);
});

it('keeps manual clearing from older clients when category source is omitted', function () {
    [$user, $household] = ownerWithHousehold();
    Passport::actingAs($user);
    $ingredient = Ingredient::factory()->for($household)->create(['category' => 'pantry', 'category_source' => 'ai']);

    $this->postJson('/api/v1/sync', ['household_id' => $household->id, 'changes' => [
        'ingredients' => [[
            'id' => $ingredient->uuid, 'name' => $ingredient->name, 'category' => null,
            'updated_at' => now()->addSecond()->toISOString(),
        ]],
    ]])->assertOk();
    expect($ingredient->refresh()->category_source)->toBe('user');
});

it('prunes expired usage without resetting current budgets', function () {
    $this->freezeTime();
    DB::table('ai_daily_usage')->insert([
        ['day' => now('UTC')->subDays(8)->toDateString(), 'scope' => 'global', 'feature' => 'suggestions', 'used' => 15],
        ['day' => now('UTC')->toDateString(), 'scope' => 'global', 'feature' => 'suggestions', 'used' => 5],
    ]);

    $this->artisan('ai:prune-usage')->assertSuccessful();
    $this->assertDatabaseCount('ai_daily_usage', 1);
    $this->assertDatabaseHas('ai_daily_usage', ['day' => now('UTC')->toDateString(), 'used' => 5]);
});
