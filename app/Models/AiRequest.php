<?php

namespace App\Models;

use Database\Factories\AiRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per provider call (or cache hit) so admins can see usage, latency,
 * tokens and cost per feature and model. The request context, the provider's
 * answer and any error are kept for a month so failures can be inspected.
 */
#[Fillable(['request_id', 'user_id', 'household_id', 'feature', 'model', 'status', 'duration_ms', 'input_tokens', 'output_tokens', 'cost', 'request', 'response', 'error', 'created_at'])]
class AiRequest extends Model
{
    /** @use HasFactory<AiRequestFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CACHED = 'cached';

    /** Days the request and response bodies are kept before they are cleared. */
    public const BODY_RETENTION_DAYS = 30;

    /** @return array<string, string> */
    public static function featureLabels(): array
    {
        return ['categorization' => 'Categorization', 'suggestions' => 'Suggestions'];
    }

    /**
     * Failed provider calls in the last day grouped by feature and exception
     * class, with a link to the newest example.
     *
     * @return list<array{feature: string, model: string, exception: string|null, count: int, latest_id: int, latest_at: string|null}>
     */
    public static function recentErrorGroups(int $hours = 24, int $limit = 10): array
    {
        $rows = static::query()
            ->where('status', self::STATUS_FAILED)
            ->where('created_at', '>=', now()->subHours($hours))
            ->latest('id')
            ->limit(2000)
            ->get(['id', 'feature', 'model', 'error', 'created_at']);

        return $rows
            ->groupBy(fn (self $row) => $row->feature.'|'.$row->model.'|'.ApiRequest::exceptionClass($row->error))
            ->map(fn ($group) => [
                'feature' => $group->first()->feature,
                'model' => $group->first()->model,
                'exception' => ApiRequest::exceptionClass($group->first()->error),
                'count' => $group->count(),
                'latest_id' => (int) $group->first()->id,
                'latest_at' => $group->first()->created_at?->toIso8601String(),
            ])
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration_ms' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'float',
            'request' => 'array',
            'response' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }
}
