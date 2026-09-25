<?php

namespace App\Actions\Ai;

use App\Models\AiRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/** Explicit, guest-accessible generation; never reads or writes household content. */
class RunWeekPlanning
{
    public function __construct(private AiUsage $usage, private RunAiRequest $runner, private AiConfiguration $configuration, private ReviewDinnerRecipes $reviewer) {}

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function handle(string $ip, array $context, GenerateWeekDinners $agent): array
    {
        if (! $this->runner->available()) {
            $this->usage->reject('unavailable', 503);
        }
        // 192 bits of the HMAC is plenty, and keeps the scope inside ai_daily_usage.scope (64 chars).
        $scope = 'planner:'.substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 48);
        $model = $this->configuration->model('suggestions');
        $key = $scope.':v3:'.hash('sha256', json_encode([$model, $this->configuration->reasoningEffort('suggestions'), $context], JSON_THROW_ON_ERROR));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }
        $lock = Cache::lock($scope.':lock', 90);
        if (! $lock->get()) {
            $this->usage->reject('busy', 429, 5);
        }
        try {
            if (is_array($cached = Cache::get($key))) {
                return $cached;
            }
            DB::transaction(function () use ($scope): void {
                foreach (['global' => 'global', 'ip' => $scope] as $kind => $owner) {
                    $key = ['day' => now('UTC')->toDateString(), 'scope' => $owner, 'feature' => 'week_planning'];
                    DB::table('ai_daily_usage')->insertOrIgnore([...$key, 'used' => 0]);
                    if (! DB::table('ai_daily_usage')->where($key)
                        ->where('used', '<', max(0, (int) config('assistance.week_planning.'.$kind)))->increment('used')) {
                        $this->usage->reject('daily_limit', 429, (int) now('UTC')->diffInSeconds(now('UTC')->addDay()->startOfDay()));
                    }
                }
            }, 3);
            $startedAt = hrtime(true);
            $result = null;
            $stage = 'generation';
            try {
                $result = $agent->handle($context);
                $available = array_column($context['available'], null, 'id');
                $dinners = array_map(fn (array $dinner) => $dinner['existing_id'] === null ? $dinner : [
                    'existing_id' => $dinner['existing_id'],
                    'name' => $available[$dinner['existing_id']]['name'],
                    'ingredients' => $available[$dinner['existing_id']]['ingredients'],
                ], $result->data['dinners']);
                $stage = 'review';
                $review = $this->reviewer->handle([
                    'dinners' => $dinners, 'servings' => $context['servings'],
                    'excluded_ingredients' => $context['excluded_ingredients'] ?? [], 'shortcuts' => $context['shortcuts'],
                ]);
                $result = new AiResult($result->data, $result->inputTokens + $review->inputTokens,
                    $result->outputTokens + $review->outputTokens, $result->cost + $review->cost);
                if ($review->data['issues'] !== []) {
                    throw new UnexpectedValueException('Recipe consistency review failed.');
                }
            } catch (Throwable $e) {
                Log::warning('Week suggestion failed.', ['exception' => $e::class, 'stage' => $stage]);
                $this->record($model, $startedAt, $result, $this->describeFailure($e, $stage, $context));
                $this->usage->reject('unavailable', 503);
            }
            $this->record($model, $startedAt, $result);
            Cache::put($key, $result->data, (int) config('assistance.cache_seconds'));

            return $result->data;
        } finally {
            $lock->release();
        }
    }

    /** Keep useful HTTP diagnostics without retaining raw provider bodies or guest input. */
    private function describeFailure(Throwable $exception, string $stage, array $context): string
    {
        $description = $exception::class.': '.$stage;
        while (! $exception instanceof RequestException && $exception->getPrevious() !== null) {
            $exception = $exception->getPrevious();
        }
        if (! $exception instanceof RequestException) {
            return $description;
        }

        $description .= '; HTTP '.$exception->response->status();
        $message = $exception->response->json('error.message');
        $details = is_string($message) ? [$message] : [];
        foreach (['error_type', 'provider_code'] as $field) {
            $value = $exception->response->json('error.metadata.'.$field);
            if (is_string($value) || is_int($value)) {
                $details[] = $field.'='.$value;
            }
        }
        // Some providers nest their actual rejection reason in a JSON string.
        // Extract only the message, never persist the raw body or metadata.
        $raw = $exception->response->json('error.metadata.raw');
        $upstreamMessage = is_string($raw) ? data_get(json_decode($raw, true), 'error.message') : null;
        if (is_string($upstreamMessage)) {
            $details[] = $upstreamMessage;
        }
        $message = implode('; ', $details);

        $redactions = [];
        $privateValues = array_intersect_key($context, array_flip([
            'preferences', 'exclude', 'excluded_ingredients', 'reuse_ingredients', 'available',
        ]));
        $privateValues['provider_key'] = (string) config('ai.providers.openrouter.key');
        array_walk_recursive($privateValues, function (mixed $value) use (&$redactions): void {
            if (is_string($value) && trim($value) !== '') {
                $redactions[$value] = '[redacted]';
                $redactions[trim($value)] = '[redacted]';
                $redactions[substr(json_encode($value, JSON_THROW_ON_ERROR), 1, -1)] = '[redacted]';
            }
        });
        $message = strtr($message, $redactions);
        $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [redacted]', $message) ?? '';

        return mb_substr($description.($message !== '' ? '; '.$message : ''), 0, 4000);
    }

    /** Preference text is deliberately omitted from persistent request logs. */
    private function record(string $model, int $startedAt, ?AiResult $result, ?string $error = null): void
    {
        AiRequest::query()->create([
            'feature' => 'week_planning', 'model' => $model,
            'status' => $error === null ? AiRequest::STATUS_OK : AiRequest::STATUS_FAILED,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'input_tokens' => $result?->inputTokens ?? 0, 'output_tokens' => $result?->outputTokens ?? 0,
            'cost' => $result?->cost ?? 0, 'error' => $error, 'created_at' => now(),
        ]);
    }
}
