<?php

namespace App\Actions\Ai;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class AiUsage
{
    /** Reserve before contacting the provider; failed calls also consume budget. */
    public function reserve(User $user, Household $household, string $feature): void
    {
        $day = now('UTC')->toDateString();
        DB::transaction(function () use ($user, $household, $feature, $day): void {
            foreach ($this->scopes($user, $household) as $kind => $scope) {
                $key = ['day' => $day, 'scope' => $scope, 'feature' => $feature];
                DB::table('ai_daily_usage')->insertOrIgnore([...$key, 'used' => 0]);
                $updated = DB::table('ai_daily_usage')->where($key)
                    ->where('used', '<', max(0, (int) config("assistance.limits.{$feature}.{$kind}")))
                    ->increment('used');
                if (! $updated) {
                    $this->reject('daily_limit', 429, (int) now('UTC')->diffInSeconds(now('UTC')->addDay()->startOfDay()));
                }
            }
        }, 3);
    }

    /** @return array<string, mixed> */
    public function status(User $user, Household $household): array
    {
        $result = ['resets_at' => now('UTC')->addDay()->startOfDay()->toISOString()];
        foreach (['categorization', 'suggestions'] as $feature) {
            $remaining = [];
            foreach ($this->scopes($user, $household) as $kind => $scope) {
                $limit = max(0, (int) config("assistance.limits.{$feature}.{$kind}"));
                $used = (int) DB::table('ai_daily_usage')->where([
                    'day' => now('UTC')->toDateString(), 'scope' => $scope, 'feature' => $feature,
                ])->value('used');
                $remaining[] = max(0, $limit - $used);
                if ($kind !== 'global') {
                    $result[$feature][$kind] = ['limit' => $limit, 'remaining' => max(0, $limit - $used)];
                }
            }
            $result[$feature]['remaining'] = min($remaining);
        }

        return $result;
    }

    /** Fixed order avoids deadlocks while reserving all budgets atomically. @return array<string, string> */
    private function scopes(User $user, Household $household): array
    {
        return ['global' => 'global', 'household' => 'household:'.$household->id, 'user' => 'user:'.$user->id];
    }

    public function reject(string $code, int $status, int $retryAfter = 60): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'AI assistance is currently unavailable.', 'code' => $code,
        ], $status, ['Retry-After' => (string) max(1, $retryAfter)]));
    }
}
