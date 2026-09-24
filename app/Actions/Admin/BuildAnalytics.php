<?php

namespace App\Actions\Admin;

use App\Actions\Ai\AiConfiguration;
use App\Models\AiRequest;
use App\Models\ApiRequest;
use App\Models\Household;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the handful of numbers shown on the admin analytics page.
 * Everything is counted over a trailing window of whole UTC days ending
 * today. Per-request detail lives in the AI and API request logs instead.
 */
class BuildAnalytics
{
    public function __construct(private AiConfiguration $configuration) {}

    /** @return array<string, mixed> */
    public function handle(int $days = 30): array
    {
        $days = max(7, min(90, $days));
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $from = $today->subDays($days - 1);
        $labels = collect(range(0, $days - 1))->map(fn (int $offset) => $from->addDays($offset)->toDateString());

        return [
            'days' => $days,
            'from' => $from->toDateString(),
            'to' => $today->toDateString(),
            'app' => $this->app($from, $labels),
            'api' => $this->api($from),
            'ai' => $this->ai($from, $labels),
        ];
    }

    /**
     * @param  Collection<int, string>  $labels
     * @return array<string, mixed>
     */
    private function app(CarbonImmutable $from, $labels): array
    {
        // Every write to a household's content advances its sync version, which
        // touches the household row: one small table instead of four large ones.
        $activeHouseholds = Household::query()->where('updated_at', '>=', CarbonImmutable::now('UTC')->subDays(7))->count();
        $signups = User::query()->where('created_at', '>=', $from)
            ->selectRaw($this->dayExpression('created_at').' as day, count(*) as total')
            ->groupBy('day')->pluck('total', 'day');

        return [
            'totals' => [
                'users' => User::query()->count(),
                'verified_users' => User::query()->whereNotNull('email_verified_at')->count(),
                'new_users' => User::query()->where('created_at', '>=', $from)->count(),
                'households' => Household::query()->count(),
                'active_households' => $activeHouseholds,
            ],
            'signups' => $labels->map(fn (string $day) => ['day' => $day, 'value' => (int) ($signups[$day] ?? 0)])->values()->all(),
        ];
    }

    /** @return array<string, int> */
    private function api(CarbonImmutable $from): array
    {
        $totals = ApiRequest::query()->where('created_at', '>=', $from)->toBase()
            ->selectRaw('count(*) as requests')
            ->selectRaw('sum(case when status between 400 and 499 then 1 else 0 end) as client_errors')
            ->selectRaw('sum(case when status >= 500 then 1 else 0 end) as server_errors')
            ->first();

        return [
            'requests' => (int) $totals->requests,
            'client_errors' => (int) $totals->client_errors,
            'server_errors' => (int) $totals->server_errors,
        ];
    }

    /**
     * @param  Collection<int, string>  $labels
     * @return array<string, mixed>
     */
    private function ai(CarbonImmutable $from, $labels): array
    {
        $requests = AiRequest::query()->where('created_at', '>=', $from);
        $daily = (clone $requests)
            ->selectRaw($this->dayExpression('created_at').' as day, status, count(*) as total')
            ->groupBy('day', 'status')->get();
        $byDay = $labels->map(function (string $day) use ($daily): array {
            $rows = $daily->where('day', $day);

            return [
                'day' => $day,
                'ok' => (int) $rows->where('status', AiRequest::STATUS_OK)->sum('total'),
                'cached' => (int) $rows->where('status', AiRequest::STATUS_CACHED)->sum('total'),
                'failed' => (int) $rows->where('status', AiRequest::STATUS_FAILED)->sum('total'),
            ];
        })->values()->all();
        $totals = (clone $requests)->toBase()
            ->selectRaw('count(*) as requests')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as cached', [AiRequest::STATUS_CACHED])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as failed', [AiRequest::STATUS_FAILED])
            ->selectRaw('avg(case when status = ? then duration_ms end) as avg_duration_ms', [AiRequest::STATUS_OK])
            ->selectRaw('sum(case when status = ? then cost else 0 end) as cost', [AiRequest::STATUS_OK])
            ->first();
        $today = CarbonImmutable::now('UTC')->toDateString();
        $limits = [];
        foreach (array_keys(AiRequest::featureLabels()) as $feature) {
            $limits[] = [
                'feature' => $feature,
                'used' => (int) DB::table('ai_daily_usage')->where(['day' => $today, 'scope' => 'global', 'feature' => $feature])->value('used'),
                'limit' => $this->configuration->limit($feature, 'global'),
            ];
        }

        return [
            'features' => AiRequest::featureLabels(),
            'totals' => [
                'requests' => (int) $totals->requests,
                'provider_requests' => (int) $totals->requests - (int) $totals->cached,
                'cached' => (int) $totals->cached,
                'failed' => (int) $totals->failed,
                'avg_duration_ms' => (int) round((float) $totals->avg_duration_ms),
                'cost' => round((float) $totals->cost, 6),
            ],
            'daily' => $byDay,
            'today' => $limits,
        ];
    }

    /** Portable "date part" expression for the SQLite test database and MySQL/Postgres in production. */
    private function dayExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            default => "date_format({$column}, '%Y-%m-%d')",
        };
    }
}
