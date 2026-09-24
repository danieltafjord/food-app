<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\SortsAndPaginates;
use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\ApiRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AiRequestController extends Controller
{
    use SortsAndPaginates;

    /** @var list<string> */
    private const STATUSES = [AiRequest::STATUS_OK, AiRequest::STATUS_FAILED, AiRequest::STATUS_CACHED];

    /** @var array<string, string> sort key => column */
    private const SORTS = ['created' => 'id', 'duration' => 'duration_ms', 'tokens' => 'input_tokens + output_tokens', 'cost' => 'cost'];

    /**
     * Every AI request, newest first by default, with status, feature, user
     * and free-text filters plus sorting by latency, tokens or cost. The text
     * filter searches the stored request context, the error message and the
     * model name.
     */
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'all');
        $status = in_array($status, self::STATUSES, true) ? $status : 'all';
        $feature = (string) $request->query('feature', 'all');
        $feature = array_key_exists($feature, AiRequest::featureLabels()) ? $feature : 'all';
        $userId = (int) $request->query('user', 0);
        $householdId = (int) $request->query('household', 0);
        $search = trim((string) $request->query('search', ''));
        [$sort, $direction] = $this->sorting($request, self::SORTS, 'created');

        $requests = AiRequest::query()
            // The stored request, response and error are only shown on the detail
            // page; the list needs just the subject's name out of the request.
            ->select(['id', 'user_id', 'household_id', 'feature', 'model', 'status', 'duration_ms', 'input_tokens', 'output_tokens', 'cost', 'created_at', 'request->name as request_name'])
            ->selectRaw('CASE WHEN error IS NULL THEN 0 ELSE 1 END AS has_error')
            ->with(['user:id,name,email', 'household:id,name'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($feature !== 'all', fn ($query) => $query->where('feature', $feature))
            ->when($userId > 0, fn ($query) => $query->where('user_id', $userId))
            ->when($householdId > 0, fn ($query) => $query->where('household_id', $householdId))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    // whereLike casts the json column to text on PostgreSQL.
                    $query->whereLike('request', "%{$search}%")
                        ->orWhereLike('error', "%{$search}%")
                        ->orWhereLike('model', "%{$search}%");
                    // PostgreSQL rejects comparing a uuid column with anything that is not one.
                    if (Str::isUuid($search)) {
                        $query->orWhere('request_id', $search);
                    }
                });
            })
            ->orderByRaw(self::SORTS[$sort].' '.$direction)
            ->orderBy('id', 'desc')
            ->paginate($this->perPage($request))
            ->withQueryString()
            ->through(fn (AiRequest $row) => self::row($row, $row->request_name, (bool) $row->has_error));

        $user = $userId > 0 ? User::query()->find($userId, ['id', 'name']) : null;
        $household = $householdId > 0 ? Household::query()->find($householdId, ['id', 'name']) : null;

        return Inertia::render('admin/AiRequests', [
            'requests' => $requests,
            // Lazy, so filtering and paging (partial reloads of `requests`) skip it.
            'errorGroups' => fn () => AiRequest::recentErrorGroups(),
            'features' => AiRequest::featureLabels(),
            'filters' => [
                'status' => $status,
                'feature' => $feature,
                'user' => $user ? ['id' => $user->id, 'name' => $user->name] : null,
                'household' => $household ? ['id' => $household->id, 'name' => $household->name] : null,
                'search' => $search,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $requests->perPage(),
            ],
            'pageSizes' => self::pageSizes(),
        ]);
    }

    /**
     * One AI request with the context that was sent, what came back and the
     * error if it failed.
     */
    public function show(AiRequest $aiRequest): Response
    {
        $aiRequest->load(['user:id,name,email', 'household:id,name']);
        $apiRequest = $aiRequest->request_id
            ? ApiRequest::query()->where('request_id', $aiRequest->request_id)->latest('id')->first(['id', 'status'])
            : null;

        return Inertia::render('admin/AiRequestShow', [
            'request' => self::row($aiRequest, $aiRequest->request['name'] ?? null, $aiRequest->error !== null) + [
                'request_id' => $aiRequest->request_id,
                'api_request' => $apiRequest ? ['id' => $apiRequest->id, 'status' => $apiRequest->status] : null,
                'request' => $aiRequest->request,
                'response' => $aiRequest->response,
                'error' => $aiRequest->error,
                'input_tokens' => $aiRequest->input_tokens,
                'output_tokens' => $aiRequest->output_tokens,
                'bodies_retained_until' => $aiRequest->created_at?->addDays(AiRequest::BODY_RETENTION_DAYS)->toIso8601String(),
            ],
            'features' => AiRequest::featureLabels(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function row(AiRequest $row, mixed $subject, bool $hasError): array
    {
        return [
            'id' => $row->id,
            'feature' => $row->feature,
            'model' => $row->model,
            'status' => $row->status,
            'duration_ms' => $row->duration_ms,
            'tokens' => $row->input_tokens + $row->output_tokens,
            'cost' => $row->cost,
            // The ingredient or dinner name the request was about.
            'summary' => is_string($subject) ? mb_substr($subject, 0, 80) : null,
            'has_error' => $hasError,
            'user' => $row->user ? ['id' => $row->user->id, 'name' => $row->user->name, 'email' => $row->user->email] : null,
            'household' => $row->household ? ['id' => $row->household->id, 'name' => $row->household->name] : null,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
