<?php

namespace App\Actions\Ai;

use App\Models\AiRequest;
use App\Models\Household;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunAiRequest
{
    public function __construct(private AiUsage $usage, private AiConfiguration $configuration) {}

    public function available(): bool
    {
        return (bool) config('assistance.enabled') && filled(config('ai.providers.openrouter.key'));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  Closure(): AiResult  $generate
     * @return array<string, mixed>
     */
    public function handle(User $user, Household $household, string $feature, array $context, Closure $generate): array
    {
        if (! $user->hasVerifiedEmail()) {
            $this->usage->reject('verification_required', 403);
        }
        if (! $user->getAttribute('ai_'.$feature.'_enabled')) {
            $this->usage->reject('disabled', 403);
        }
        if (! $this->available()) {
            $this->usage->reject('unavailable', 503);
        }
        // Include scope, model and prompt revision so caches cannot cross households or model changes.
        $model = $this->configuration->model($feature);
        $effort = $this->configuration->reasoningEffort($feature)?->value;
        $revision = $feature === 'categorization' ? 'v2' : 'v1';
        $key = 'assistance:'.$revision.':'.$household->id.':'.$feature.':'.hash('sha256', json_encode([$model, $effort, config('assistance.classification_confidence'), $context], JSON_THROW_ON_ERROR));
        if (is_array($cached = Cache::get($key))) {
            $this->record($user, $household, $feature, $model, AiRequest::STATUS_CACHED);

            return $cached;
        }
        $lock = Cache::lock($key.':lock', 60);
        if (! $lock->get()) {
            $this->usage->reject('busy', 429, 5);
        }
        try {
            if (is_array($cached = Cache::get($key))) {
                $this->record($user, $household, $feature, $model, AiRequest::STATUS_CACHED);

                return $cached;
            }
            $this->usage->reserve($user, $household, $feature);
            $startedAt = hrtime(true);
            try {
                $result = $generate();
            } catch (Throwable $e) {
                // Provider exceptions can contain prompts or keys; log only the exception class.
                Log::warning('AI provider request failed.', ['feature' => $feature, 'exception' => $e::class]);
                $this->record($user, $household, $feature, $model, AiRequest::STATUS_FAILED, $startedAt);
                $this->usage->reject('unavailable', 503);
            }
            $this->record($user, $household, $feature, $model, AiRequest::STATUS_OK, $startedAt, $result);
            Cache::put($key, $result->data, (int) config('assistance.cache_seconds'));

            return $result->data;
        } finally {
            $lock->release();
        }
    }

    private function record(User $user, Household $household, string $feature, string $model, string $status, ?int $startedAt = null, ?AiResult $result = null): void
    {
        AiRequest::query()->create([
            'user_id' => $user->id,
            'household_id' => $household->id,
            'feature' => $feature,
            'model' => $model,
            'status' => $status,
            'duration_ms' => $startedAt === null ? 0 : (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'input_tokens' => $result?->inputTokens ?? 0,
            'output_tokens' => $result?->outputTokens ?? 0,
            'cost' => $result?->cost ?? 0.0,
            'created_at' => now(),
        ]);
    }
}
