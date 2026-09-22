<?php

namespace App\Actions\Admin;

use App\Actions\Ai\AiConfiguration;
use App\Models\AiRequest;
use App\Models\ApiTokenDetail;
use App\Models\Dinner;
use App\Models\DinnerPlan;
use App\Models\Household;
use App\Models\Ingredient;
use App\Models\OAuthHouseholdGrant;
use App\Models\ShoppingList;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the numbers shown on the admin analytics page. Everything is
 * counted over a trailing window of whole UTC days ending today.
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
            'ai' => $this->ai($from, $labels),
        ];
    }

    /**
     * @param  Collection<int, string>  $labels
     * @return array<string, mixed>
     */
    private function app(CarbonImmutable $from, $labels): array
    {
        $weekAgo = CarbonImmutable::now('UTC')->subDays(7);
        $activeHouseholds = collect([Dinner::class, DinnerPlan::class, ShoppingList::class, Ingredient::class])
            ->flatMap(fn (string $model) => $model::withTrashed()->where('synced_at', '>=', $weekAgo)->distinct()->pluck('household_id'))
            ->unique()->count();
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
                'dinners' => Dinner::query()->count(),
                'dinner_plans' => DinnerPlan::query()->count(),
                'shopping_lists' => ShoppingList::query()->count(),
                'ingredients' => Ingredient::query()->count(),
                'api_tokens' => ApiTokenDetail::query()->count(),
                'connected_apps' => OAuthHouseholdGrant::query()->count(),
            ],
            'signups' => $labels->map(fn (string $day) => ['day' => $day, 'value' => (int) ($signups[$day] ?? 0)])->values()->all(),
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
            ->selectRaw($this->dayExpression('created_at').' as day, feature, status, count(*) as total')
            ->groupBy('day', 'feature', 'status')->get();
        $byFeature = [];
        foreach ($labels as $day) {
            foreach (array_keys(self::featureLabels()) as $feature) {
                $rows = $daily->where('day', $day)->where('feature', $feature);
                $byFeature[$feature][] = [
                    'day' => $day,
                    'ok' => (int) $rows->where('status', AiRequest::STATUS_OK)->sum('total'),
                    'cached' => (int) $rows->where('status', AiRequest::STATUS_CACHED)->sum('total'),
                    'failed' => (int) $rows->where('status', AiRequest::STATUS_FAILED)->sum('total'),
                ];
            }
        }
        $provider = (clone $requests)->where('status', '!=', AiRequest::STATUS_CACHED);
        $ok = (clone $requests)->where('status', AiRequest::STATUS_OK);
        $models = (clone $requests)
            ->selectRaw('feature, model, count(*) as requests, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost) as cost')
            ->groupBy('feature', 'model')->orderByDesc('requests')->get()
            ->map(fn (AiRequest $row) => [
                'feature' => $row->feature,
                'model' => $row->model,
                'requests' => (int) $row->requests,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'cost' => round((float) $row->cost, 6),
            ])->values()->all();
        $today = CarbonImmutable::now('UTC')->toDateString();
        $limits = [];
        foreach (array_keys(self::featureLabels()) as $feature) {
            $limits[] = [
                'feature' => $feature,
                'used' => (int) DB::table('ai_daily_usage')->where(['day' => $today, 'scope' => 'global', 'feature' => $feature])->value('used'),
                'limit' => $this->configuration->limit($feature, 'global'),
            ];
        }
        $households = (clone $requests)
            ->whereNotNull('household_id')
            ->selectRaw('household_id, count(*) as requests, sum(case when status = ? then 1 else 0 end) as failed, sum(cost) as cost, count(distinct user_id) as users', [AiRequest::STATUS_FAILED])
            ->groupBy('household_id')->orderByDesc('requests')->limit(10)->get();
        $householdNames = Household::query()->whereIn('id', $households->pluck('household_id'))->pluck('name', 'id');
        $topHouseholds = $households->map(fn (AiRequest $row) => [
            'household_id' => (int) $row->household_id,
            'name' => $householdNames[$row->household_id] ?? 'Deleted household',
            'requests' => (int) $row->requests,
            'failed' => (int) $row->failed,
            'users' => (int) $row->users,
            'cost' => round((float) $row->cost, 6),
        ])->values()->all();

        return [
            'features' => self::featureLabels(),
            'totals' => [
                'requests' => (clone $requests)->count(),
                'provider_requests' => (clone $provider)->count(),
                'cached' => (clone $requests)->where('status', AiRequest::STATUS_CACHED)->count(),
                'failed' => (clone $requests)->where('status', AiRequest::STATUS_FAILED)->count(),
                'avg_duration_ms' => (int) round((float) (clone $ok)->avg('duration_ms')),
                'input_tokens' => (int) (clone $ok)->sum('input_tokens'),
                'output_tokens' => (int) (clone $ok)->sum('output_tokens'),
                'cost' => round((float) (clone $ok)->sum('cost'), 6),
                'categorization_users' => User::query()->where('ai_categorization_enabled', true)->count(),
                'suggestions_users' => User::query()->where('ai_suggestions_enabled', true)->count(),
            ],
            'daily' => $byFeature,
            'models' => $models,
            'households' => $topHouseholds,
            'today' => $limits,
        ];
    }

    /** @return array<string, string> */
    private static function featureLabels(): array
    {
        return ['categorization' => 'Categorization', 'suggestions' => 'Suggestions'];
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
