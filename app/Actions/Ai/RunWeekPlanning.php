<?php

namespace App\Actions\Ai;

use App\Models\AiRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Explicit, guest-accessible generation; never reads or writes household content. */
class RunWeekPlanning
{
    public function __construct(private AiUsage $usage, private RunAiRequest $runner, private AiConfiguration $configuration) {}

    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function handle(string $ip, array $context, GenerateWeekDinners $agent): array
    {
        if (! $this->runner->available()) {
            $this->usage->reject('unavailable', 503);
        }
        // 192 bits of the HMAC is plenty, and keeps the scope inside ai_daily_usage.scope (64 chars).
        $scope = 'planner:'.substr(hash_hmac('sha256', $ip, (string) config('app.key')), 0, 48);
        $model = $this->configuration->model('suggestions');
        $key = $scope.':v1:'.hash('sha256', json_encode([$model, $this->configuration->reasoningEffort('suggestions'), $context], JSON_THROW_ON_ERROR));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }
        $lock = Cache::lock($scope.':lock', 70);
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
            try {
                $result = $agent->handle($context);
            } catch (Throwable $e) {
                Log::warning('Week suggestion failed.', ['exception' => $e::class]);
                $this->record($model, $startedAt, null, $e::class);
                $this->usage->reject('unavailable', 503);
            }
            $this->record($model, $startedAt, $result);
            Cache::put($key, $result->data, (int) config('assistance.cache_seconds'));

            return $result->data;
        } finally {
            $lock->release();
        }
    }

    /** Preference text is deliberately omitted from persistent request logs. */
    private function record(string $model, int $startedAt, ?AiResult $result, ?string $error = null): void
    {
        AiRequest::query()->create([
            'feature' => 'week_planning', 'model' => $model,
            'status' => $result ? AiRequest::STATUS_OK : AiRequest::STATUS_FAILED,
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'input_tokens' => $result?->inputTokens ?? 0, 'output_tokens' => $result?->outputTokens ?? 0,
            'cost' => $result?->cost ?? 0, 'error' => $error, 'created_at' => now(),
        ]);
    }
}
