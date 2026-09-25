<?php

namespace App\Actions\Ai;

use App\Http\Middleware\LogApiRequest;
use App\Models\AiRequest;
use App\Models\Household;
use App\Models\User;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunAiRequest
{
    public function __construct(private AiUsage $usage, private AiConfiguration $configuration) {}

    public function available(): bool
    {
        return $this->configuration->enabled() && $this->configuration->providerConfigured();
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
        $revision = $feature === 'suggestions' ? 'v4' : 'v2';
        $key = 'assistance:'.$revision.':'.$household->id.':'.$feature.':'.hash('sha256', json_encode([$model, $effort, config('assistance.classification_confidence'), $context], JSON_THROW_ON_ERROR));
        if (is_array($cached = Cache::get($key))) {
            $this->record($user, $household, $feature, $model, AiRequest::STATUS_CACHED, $context, response: $cached);

            return $cached;
        }
        $lock = Cache::lock($key.':lock', 60);
        if (! $lock->get()) {
            $this->usage->reject('busy', 429, 5);
        }
        try {
            if (is_array($cached = Cache::get($key))) {
                $this->record($user, $household, $feature, $model, AiRequest::STATUS_CACHED, $context, response: $cached);

                return $cached;
            }
            $this->usage->reserve($user, $household, $feature);
            $startedAt = hrtime(true);
            try {
                $result = $generate();
            } catch (Throwable $e) {
                // Provider exceptions can contain prompts or keys; the app log gets only the
                // class, while the admin request log keeps a redacted message.
                Log::warning('AI provider request failed.', ['feature' => $feature, 'exception' => $e::class]);
                $this->record($user, $household, $feature, $model, AiRequest::STATUS_FAILED, $context, $startedAt, error: $this->describe($e));
                $this->usage->reject('unavailable', 503);
            }
            $this->record($user, $household, $feature, $model, AiRequest::STATUS_OK, $context, $startedAt, $result, [
                'data' => $result->data,
                'raw' => $result->raw,
            ]);
            Cache::put($key, $result->data, (int) config('assistance.cache_seconds'));

            return $result->data;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $response
     */
    private function record(User $user, Household $household, string $feature, string $model, string $status, array $context, ?int $startedAt = null, ?AiResult $result = null, ?array $response = null, ?string $error = null): void
    {
        AiRequest::query()->create([
            'request_id' => request()->attributes->get(LogApiRequest::REQUEST_ID),
            'user_id' => $user->id,
            'household_id' => $household->id,
            'feature' => $feature,
            'model' => $model,
            'status' => $status,
            'duration_ms' => $startedAt === null ? 0 : (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'input_tokens' => $result?->inputTokens ?? 0,
            'output_tokens' => $result?->outputTokens ?? 0,
            'cost' => $result?->cost ?? 0.0,
            'request' => $context,
            'response' => $response,
            'error' => $error,
            'created_at' => now(),
        ]);
    }

    /**
     * A one-line description of a provider failure with the server key and
     * any bearer token blanked out, plus the provider's response body when
     * the failure was an HTTP error.
     */
    public function describe(Throwable $e): string
    {
        $message = $e::class.': '.$e->getMessage();
        if ($e instanceof RequestException) {
            $message .= "\n".$e->response->body();
        }
        $secrets = array_filter([(string) config('ai.providers.openrouter.key')]);
        $message = str_replace($secrets, '[redacted]', $message);
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/', 'Bearer [redacted]', $message) ?? $message;

        return mb_substr($message, 0, 4000);
    }
}
