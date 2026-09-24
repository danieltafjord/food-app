<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\SortsAndPaginates;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ApiRequestController extends Controller
{
    use SortsAndPaginates;

    /** @var list<string> */
    private const OUTCOMES = ['all', 'errors', 'server_errors'];

    /** @var array<string, string> sort key => column */
    private const SORTS = ['created' => 'id', 'status' => 'status', 'duration' => 'duration_ms'];

    /**
     * Every logged API request, newest first by default, filtered by channel,
     * outcome, method, user and a free-text match on the path, route name or
     * error, and sortable by status code or duration.
     */
    public function index(Request $request): Response
    {
        $channel = (string) $request->query('channel', 'all');
        $channel = array_key_exists($channel, ApiRequest::channelLabels()) ? $channel : 'all';
        $outcome = (string) $request->query('outcome', 'all');
        $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : 'all';
        $method = strtoupper((string) $request->query('method', ''));
        $method = in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true) ? $method : '';
        $userId = (int) $request->query('user', 0);
        $search = trim((string) $request->query('search', ''));
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'created');

        $requests = ApiRequest::query()
            // The bodies and trace are only shown on the detail page.
            ->select(['id', 'channel', 'user_id', 'method', 'path', 'route', 'status', 'duration_ms', 'created_at'])
            ->selectRaw('CASE WHEN error IS NULL THEN 0 ELSE 1 END AS has_error')
            ->with('user:id,name,email')
            ->when($channel !== 'all', fn ($query) => $query->where('channel', $channel))
            ->when($outcome === 'errors', fn ($query) => $query->where('status', '>=', 400))
            ->when($outcome === 'server_errors', fn ($query) => $query->where('status', '>=', 500))
            ->when($method !== '', fn ($query) => $query->where('method', $method))
            ->when($userId > 0, fn ($query) => $query->where('user_id', $userId))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->whereLike('path', "%{$search}%")
                        ->orWhereLike('route', "%{$search}%")
                        ->orWhereLike('error', "%{$search}%");
                    // PostgreSQL rejects comparing a uuid column with anything that is not one.
                    if (Str::isUuid($search)) {
                        $query->orWhere('request_id', $search);
                    }
                    if (preg_match('/^\d{3}$/', $search)) {
                        $query->orWhere('status', (int) $search);
                    }
                });
            })
            ->orderBy(self::SORTS[$sort], $direction)
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (ApiRequest $row) => self::row($row, (bool) $row->has_error));

        $user = $userId > 0 ? User::query()->find($userId, ['id', 'name']) : null;

        return Inertia::render('admin/ApiRequests', [
            'requests' => $requests,
            // Lazy, so filtering and paging (partial reloads of `requests`) skip it.
            'errorGroups' => fn () => ApiRequest::recentErrorGroups(),
            'channels' => ApiRequest::channelLabels(),
            'filters' => [
                'channel' => $channel,
                'outcome' => $outcome,
                'method' => $method,
                'user' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $requests->perPage(),
            ],
            'pageSizes' => self::pageSizes(),
        ]);
    }

    /**
     * One request with the (redacted) body the client sent, the response it
     * received and the exception trace when the server failed.
     */
    public function show(ApiRequest $apiRequest): Response
    {
        $apiRequest->load(['user:id,name,email', 'household:id,name', 'apiToken.token:id,name']);

        $aiRequests = $apiRequest->request_id
            ? AiRequest::query()->where('request_id', $apiRequest->request_id)->orderBy('id')->get(['id', 'feature', 'status'])
                ->map(fn (AiRequest $row) => ['id' => $row->id, 'feature' => $row->feature, 'status' => $row->status])->all()
            : [];

        return Inertia::render('admin/ApiRequestShow', [
            'request' => self::row($apiRequest, $apiRequest->error !== null) + [
                'request_id' => $apiRequest->request_id,
                'ai_requests' => $aiRequests,
                'household' => $apiRequest->household ? ['id' => $apiRequest->household->id, 'name' => $apiRequest->household->name] : null,
                'token_name' => $apiRequest->apiToken?->token?->name,
                'ip' => $apiRequest->ip,
                'user_agent' => $apiRequest->user_agent,
                'request_body' => $apiRequest->request_body,
                'response_body' => $apiRequest->response_body,
                'error' => $apiRequest->error,
                'retained_until' => $apiRequest->created_at?->addDays(ApiRequest::RETENTION_DAYS)->toIso8601String(),
            ],
            'channels' => ApiRequest::channelLabels(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function row(ApiRequest $row, bool $hasError): array
    {
        return [
            'id' => $row->id,
            'channel' => $row->channel,
            'method' => $row->method,
            'path' => $row->path,
            'route' => $row->route,
            'status' => $row->status,
            'duration_ms' => $row->duration_ms,
            'has_error' => $hasError,
            'user' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name, 'email' => $row->user->email] : null,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
