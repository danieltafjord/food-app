<?php

namespace App\Models;

use Database\Factories\ApiRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per request to the mobile API, the public API or the MCP server,
 * kept for a month so failures can be traced back to the exact request and
 * response. Secrets in bodies are redacted before storage.
 */
#[Fillable(['request_id', 'channel', 'user_id', 'household_id', 'api_token_detail_id', 'method', 'path', 'route', 'status', 'duration_ms', 'ip', 'user_agent', 'request_body', 'response_body', 'error', 'created_at'])]
class ApiRequest extends Model
{
    /** @use HasFactory<ApiRequestFactory> */
    use HasFactory;

    use Prunable;

    public const UPDATED_AT = null;

    public const CHANNEL_APP = 'app';

    public const CHANNEL_PUBLIC = 'public';

    public const CHANNEL_MCP = 'mcp';

    /** Days a log row is kept before the nightly prune removes it. */
    public const RETENTION_DAYS = 30;

    /** @return array<string, string> */
    public static function channelLabels(): array
    {
        return [
            self::CHANNEL_APP => 'App',
            self::CHANNEL_PUBLIC => 'Public API',
            self::CHANNEL_MCP => 'MCP',
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }

    /**
     * Server errors (and any request that raised an exception) in the last
     * day, grouped by route and exception class so one bug shows up as one
     * line instead of a page of identical rows.
     *
     * @return list<array{route: string, method: string, exception: string|null, count: int, latest_id: int, latest_at: string|null}>
     */
    public static function recentErrorGroups(int $hours = 24, int $limit = 10): array
    {
        $rows = static::query()
            ->where('created_at', '>=', now()->subHours($hours))
            ->where(fn ($query) => $query->where('status', '>=', 500)->orWhereNotNull('error'))
            ->latest('id')
            ->limit(2000)
            ->get(['id', 'method', 'path', 'route', 'error', 'created_at']);

        return $rows
            ->groupBy(fn (self $row) => $row->method.' '.($row->route ?? $row->path).'|'.self::exceptionClass($row->error))
            ->map(fn ($group) => [
                'route' => $group->first()->route ?? '/'.$group->first()->path,
                'method' => $group->first()->method,
                'exception' => self::exceptionClass($group->first()->error),
                'count' => $group->count(),
                'latest_id' => (int) $group->first()->id,
                'latest_at' => $group->first()->created_at?->toIso8601String(),
            ])
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->all();
    }

    /** The class name that starts a stored error description, without its message. */
    public static function exceptionClass(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }
        $firstLine = strtok($error, "\n") ?: $error;

        return trim(explode(':', $firstLine, 2)[0]) ?: null;
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

    /** @return BelongsTo<ApiTokenDetail, $this> */
    public function apiToken(): BelongsTo
    {
        return $this->belongsTo(ApiTokenDetail::class, 'api_token_detail_id');
    }
}
