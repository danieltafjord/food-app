<?php

use App\Actions\Ai\SuggestDinnerIngredients;
use Illuminate\Support\Facades\Http;

it('previews evaluation cases without making billable requests', function () {
    Http::preventStrayRequests();
    SuggestDinnerIngredients::fake();

    $this->artisan('ai:evaluate', ['--feature' => 'suggestions'])->assertSuccessful()->expectsOutputToContain('"live": false');

    Http::assertNothingSent();
    SuggestDinnerIngredients::assertNeverPrompted();
});

it('reports deterministic suggestion quality using the production execution path', function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-key']);
    SuggestDinnerIngredients::fake([
        ['ingredients' => []], ['ingredients' => []], ['ingredients' => ['Sour cream']], ['ingredients' => ['Rømme']],
    ]);

    $this->artisan('ai:evaluate', ['--feature' => 'suggestions', '--live' => true])
        ->assertSuccessful()->expectsOutputToContain('"passed": 4');

    SuggestDinnerIngredients::assertPromptedTimes(4);
});

it('fails evaluation when a model returns no useful suggestions for clear dinners', function () {
    config(['assistance.enabled' => true, 'ai.providers.openrouter.key' => 'test-key']);
    SuggestDinnerIngredients::fake([['ingredients' => []], ['ingredients' => []], ['ingredients' => []], ['ingredients' => []]]);

    $this->artisan('ai:evaluate', ['--feature' => 'suggestions', '--live' => true])
        ->assertFailed()->expectsOutputToContain('"passed": 2');

    SuggestDinnerIngredients::assertPromptedTimes(4);
});
