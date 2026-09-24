<?php

namespace App\Actions\Ai;

use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class AiUsage
{
    public function __construct(private AiConfiguration $configuration) {}

    /** Reserve before contacting the provider; failed calls also consume budget. */
    public function reserve(User $user, Household $household, string $feature): void
    {
        $day = now('UTC')->toDateString();
        DB::transaction(function () use ($user, $household, $feature, $day): void {
            foreach ($this->scopes($user, $household) as $kind => $scope) {
                $key = ['day' => $day, 'scope' => $scope, 'feature' => $feature];
                DB::table('ai_daily_usage')->insertOrIgnore([...$key, 'used' => 0]);
                $updated = DB::table('ai_daily_usage')->where($key)
                    ->where('used', '<', $this->configuration->limit($feature, $kind))
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
        $features = ['categorization', 'suggestions', 'images'];
        $scopes = $this->scopes($user, $household);
        // One read for every feature and scope: the app asks for this often.
        $usage = DB::table('ai_daily_usage')
            ->where('day', now('UTC')->toDateString())
            ->whereIn('scope', array_values($scopes))
            ->whereIn('feature', $features)
            ->get(['scope', 'feature', 'used'])
            ->mapWithKeys(fn (object $row): array => [$row->feature.'|'.$row->scope => (int) $row->used]);
        foreach ($features as $feature) {
            $remaining = [];
            foreach ($scopes as $kind => $scope) {
                $limit = $this->configuration->limit($feature, $kind);
                $used = $usage[$feature.'|'.$scope] ?? 0;
                $remaining[] = max(0, $limit - $used);
                if ($kind !== 'global') {
                    $result[$feature][$kind] = ['limit' => $limit, 'remaining' => max(0, $limit - $used)];
                }
            }
            $result[$feature]['remaining'] = min($remaining);
        }

        return $result;
    }

    /**
     * What the admin page shows next to the budgets: how much of each
     * feature's global budget is used today, the busiest user and household,
     * and the average token use over the last 30 days for cost estimates.
     *
     * @return array{resets_at: string, features: array<string, array{global_used: int, top_user_used: int, top_household_used: int, avg_input_tokens: int, avg_output_tokens: int, sample_size: int}>}
     */
    public function overview(): array
    {
        $day = now('UTC')->toDateString();
        $today = DB::table('ai_daily_usage')->where('day', $day)->get(['scope', 'feature', 'used']);
        $averages = DB::table('ai_requests')
            ->selectRaw('feature, avg(input_tokens) as input_tokens, avg(output_tokens) as output_tokens, count(*) as total')
            ->where('status', 'ok')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('feature')
            ->get()
            ->keyBy('feature');

        $features = [];
        foreach (array_keys(AiConfiguration::FEATURES) as $feature) {
            $rows = $today->where('feature', $feature);
            $average = $averages->get($feature);
            $features[$feature] = [
                'global_used' => (int) $rows->firstWhere('scope', 'global')?->used,
                'top_user_used' => (int) $rows->filter(fn ($row) => str_starts_with($row->scope, 'user:'))->max('used'),
                'top_household_used' => (int) $rows->filter(fn ($row) => str_starts_with($row->scope, 'household:'))->max('used'),
                'avg_input_tokens' => (int) round((float) ($average->input_tokens ?? 0)),
                'avg_output_tokens' => (int) round((float) ($average->output_tokens ?? 0)),
                'sample_size' => (int) ($average->total ?? 0),
            ];
        }

        return ['resets_at' => now('UTC')->addDay()->startOfDay()->toISOString(), 'features' => $features];
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
