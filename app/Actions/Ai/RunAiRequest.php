<?php

namespace App\Actions\Ai;

use App\Models\Household;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunAiRequest
{
    public function __construct(private AiUsage $usage) {}

    public function available(): bool
    {
        return (bool) config('assistance.enabled') && filled(config('ai.providers.openrouter.key'));
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
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
        $model = config($feature === 'categorization' ? 'assistance.classification_model' : 'assistance.suggestion_model');
        $key = 'assistance:v1:'.$household->id.':'.$feature.':'.hash('sha256', json_encode([$model, config('assistance.classification_confidence'), $context], JSON_THROW_ON_ERROR));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }
        $lock = Cache::lock($key.':lock', 60);
        if (! $lock->get()) {
            $this->usage->reject('busy', 429, 5);
        }
        try {
            if (is_array($cached = Cache::get($key))) {
                return $cached;
            }
            $this->usage->reserve($user, $household, $feature);
            try {
                $result = $generate();
            } catch (Throwable $e) {
                // Provider exceptions can contain prompts or keys; log only the exception class.
                Log::warning('AI provider request failed.', ['feature' => $feature, 'exception' => $e::class]);
                $this->usage->reject('unavailable', 503);
            }
            Cache::put($key, $result, (int) config('assistance.cache_seconds'));

            return $result;
        } finally {
            $lock->release();
        }
    }
}
