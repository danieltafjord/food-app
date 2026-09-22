<?php

namespace App\Actions\Ai;

use App\Enums\ReasoningEffort;
use App\Models\AiRequest;
use App\Models\User;
use Throwable;

/**
 * Runs one real provider call with the model and reasoning effort an admin
 * is about to save, skipping budgets, caching and per-user opt-ins. The call
 * is recorded in the AI request log like any other, flagged as a test.
 */
class TestAiFeature
{
    public function __construct(
        private RunAiRequest $runner,
        private ClassifyIngredient $classifier,
        private SuggestDinnerIngredients $agent,
    ) {}

    /**
     * @return array{status: 'ok'|'failed', duration_ms: int, input_tokens: int, output_tokens: int, cost: float, data: array<string, mixed>|null, raw: array<string, mixed>|null, error: ?string, request_id: int}
     */
    public function handle(User $admin, string $feature, string $model, ?ReasoningEffort $effort, string $sample): array
    {
        $context = $this->context($feature, $sample);
        $startedAt = hrtime(true);
        $result = null;
        $error = null;

        try {
            $result = $feature === 'categorization'
                ? $this->classifier->handle($context['name'], $context['locale'], $model)
                : $this->agent->handle($context, $model, $effort);
        } catch (Throwable $e) {
            $error = $this->runner->describe($e);
        }

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $request = AiRequest::query()->create([
            'user_id' => $admin->id,
            'household_id' => $admin->current_household_id,
            'feature' => $feature,
            'model' => $model,
            'status' => $error === null ? AiRequest::STATUS_OK : AiRequest::STATUS_FAILED,
            'duration_ms' => $durationMs,
            'input_tokens' => $result?->inputTokens ?? 0,
            'output_tokens' => $result?->outputTokens ?? 0,
            'cost' => $result?->cost ?? 0.0,
            'request' => [...$context, 'test' => true, 'reasoning' => $effort?->value],
            'response' => $result ? ['data' => $result->data, 'raw' => $result->raw] : null,
            'error' => $error,
            'created_at' => now(),
        ]);

        return [
            'status' => $error === null ? 'ok' : 'failed',
            'duration_ms' => $durationMs,
            'input_tokens' => $result?->inputTokens ?? 0,
            'output_tokens' => $result?->outputTokens ?? 0,
            'cost' => $result?->cost ?? 0.0,
            'data' => $result?->data,
            'raw' => $result?->raw,
            'error' => $error,
            'request_id' => $request->id,
        ];
    }

    /** @return array<string, mixed> */
    private function context(string $feature, string $sample): array
    {
        $sample = trim($sample);

        if ($feature === 'categorization') {
            return ['name' => mb_strtolower($sample !== '' ? $sample : 'pak choi'), 'locale' => 'nb'];
        }

        return [
            'name' => $sample !== '' ? $sample : 'Tacos',
            'ingredients' => ['tortillas', 'minced beef'],
            'locale' => 'en',
            'catalogue' => [],
        ];
    }
}
