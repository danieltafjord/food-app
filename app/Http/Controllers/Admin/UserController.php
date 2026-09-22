<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\DeactivateUser;
use App\Actions\Admin\RecordAdminAction;
use App\Actions\ApiTokens\ListApiTokens;
use App\Actions\Users\DeleteAccount;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\AdminAction;
use App\Models\AiRequest;
use App\Models\ApiTokenDetail;
use App\Models\Household;
use App\Models\OAuthHouseholdGrant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /** @var array<string, string> sort key => column */
    private const SORTS = ['joined' => 'users.created_at', 'name' => 'users.name', 'households' => 'households_count'];

    /**
     * List users with search, status and household filters, sorting and pagination.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = (string) $request->query('status', 'all');
        $householdId = (int) $request->query('household', 0);
        $sort = (string) $request->query('sort', 'joined');
        $sort = array_key_exists($sort, self::SORTS) ? $sort : 'joined';
        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        $users = User::query()
            ->withCount('households')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%");
                    if (preg_match('/^#?(\d+)$/', $search, $matches)) {
                        $query->orWhere('users.id', (int) $matches[1]);
                    }
                });
            })
            ->when($status === 'active', fn ($query) => $query->whereNull('deactivated_at'))
            ->when($status === 'deactivated', fn ($query) => $query->whereNotNull('deactivated_at'))
            ->when($status === 'admins', fn ($query) => $query->where('is_admin', true))
            ->when($status === 'unverified', fn ($query) => $query->whereNull('email_verified_at'))
            ->when($status === 'ai', fn ($query) => $query->where(fn ($query) => $query->where('ai_categorization_enabled', true)->orWhere('ai_suggestions_enabled', true)))
            ->when($householdId > 0, fn ($query) => $query->whereHas('households', fn ($query) => $query->where('households.id', $householdId)))
            ->orderBy(self::SORTS[$sort], $direction)
            ->orderBy('users.id', 'desc')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $user) => self::row($user));

        $household = $householdId > 0 ? Household::query()->find($householdId, ['id', 'name']) : null;

        return Inertia::render('admin/Users', [
            'users' => $users,
            'filters' => [
                'search' => $search,
                'status' => $status,
                'household' => $household ? ['id' => $household->id, 'name' => $household->name] : null,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    /**
     * Everything an admin needs to support one account: households, tokens,
     * connected apps, recent AI requests and the audit trail.
     */
    public function show(User $user, ListApiTokens $tokens): Response
    {
        $user->loadCount('households');

        return Inertia::render('admin/UserShow', [
            'user' => self::row($user),
            'households' => $user->households()->withCount('members')->orderBy('name')->get()
                ->map(fn (Household $household) => [
                    'id' => $household->id,
                    'name' => $household->name,
                    'role' => $household->pivot->role,
                    'members_count' => (int) $household->members_count,
                    'is_current' => $household->id === $user->current_household_id,
                    'joined_at' => $household->pivot->created_at?->toIso8601String(),
                ])->values()->all(),
            'tokens' => $tokens->handle($user)->map(fn (ApiTokenDetail $token) => [
                'id' => $token->id,
                'name' => $token->token?->name,
                'household_name' => $token->household?->name,
                'can_write' => in_array('write', $token->token?->scopes ?? [], true),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
                'expires_at' => $token->token?->expires_at?->toIso8601String(),
            ])->all(),
            'connectedApps' => OAuthHouseholdGrant::query()
                ->with(['client:id,name', 'household:id,name'])
                ->where('user_id', $user->id)->latest('id')->get()
                ->map(fn (OAuthHouseholdGrant $grant) => [
                    'id' => $grant->id,
                    'name' => $grant->client?->name,
                    'household_name' => $grant->household?->name,
                    'can_write' => $grant->can_write,
                    'connected_at' => $grant->created_at?->toIso8601String(),
                ])->all(),
            'aiRequests' => AiRequest::query()->where('user_id', $user->id)->latest('id')->limit(20)->get()
                ->map(fn (AiRequest $request) => [
                    'id' => $request->id,
                    'feature' => $request->feature,
                    'model' => $request->model,
                    'status' => $request->status,
                    'duration_ms' => $request->duration_ms,
                    'tokens' => $request->input_tokens + $request->output_tokens,
                    'cost' => $request->cost,
                    'created_at' => $request->created_at?->toIso8601String(),
                ])->all(),
            'aiTotals' => [
                'requests' => AiRequest::query()->where('user_id', $user->id)->count(),
                'last_30_days' => AiRequest::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'cost' => round((float) AiRequest::query()->where('user_id', $user->id)->sum('cost'), 6),
            ],
            'actions' => AdminAction::query()->where('subject_user_id', $user->id)->latest('id')->limit(50)->get()
                ->map(fn (AdminAction $action) => $action->toRow())->all(),
        ]);
    }

    /**
     * Change a user's name, e-mail, admin flag or verification state.
     */
    public function update(UserUpdateRequest $request, User $user, RecordAdminAction $audit): RedirectResponse
    {
        $before = self::auditable($user);

        $user->fill(['name' => $request->string('name')->trim()->value(), 'email' => $request->string('email')->value()]);
        $user->is_admin = $request->boolean('is_admin');

        if ($user->isDirty('email') && ! $request->boolean('email_verified')) {
            $user->email_verified_at = null;
        } elseif ($request->boolean('email_verified') && ! $user->email_verified_at) {
            $user->email_verified_at = now();
        } elseif (! $request->boolean('email_verified')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $changes = [];
        foreach (self::auditable($user) as $key => $value) {
            if ($before[$key] !== $value) {
                $changes[$key] = ['from' => $before[$key], 'to' => $value];
            }
        }
        if ($changes !== []) {
            $audit->handle($request->user(), AdminAction::USER_UPDATED, $user, changes: $changes);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return back();
    }

    /**
     * Lock the account and revoke its sessions and tokens.
     */
    public function deactivate(Request $request, User $user, DeactivateUser $action, RecordAdminAction $audit): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot deactivate your own account.');

        $action->handle($user);
        $audit->handle($request->user(), AdminAction::USER_DEACTIVATED, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deactivated.', ['name' => $user->name])]);

        return back();
    }

    /**
     * Allow the account to sign in again.
     */
    public function reactivate(Request $request, User $user, RecordAdminAction $audit): RedirectResponse
    {
        $user->forceFill(['deactivated_at' => null])->save();
        $audit->handle($request->user(), AdminAction::USER_REACTIVATED, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name reactivated.', ['name' => $user->name])]);

        return back();
    }

    /**
     * Permanently delete the account and its data, exactly like self-service
     * deletion. The admin must type the user's e-mail to confirm.
     */
    public function destroy(Request $request, User $user, DeleteAccount $action, RecordAdminAction $audit): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot delete your own account here.');

        if (mb_strtolower(trim((string) $request->input('confirmation'))) !== mb_strtolower($user->email)) {
            throw ValidationException::withMessages(['confirmation' => __('Type the user\'s e-mail address to confirm.')]);
        }

        $audit->handle($request->user(), AdminAction::USER_DELETED, subjectLabel: "{$user->name} <{$user->email}>", changes: ['households' => $user->households()->count()]);
        $action->handle($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name deleted.', ['name' => $user->name])]);

        return to_route('admin.users.index');
    }

    /** @return array<string, mixed> */
    private static function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
            'two_factor' => $user->two_factor_confirmed_at !== null,
            'households_count' => (int) $user->households_count,
            'ai_categorization_enabled' => $user->ai_categorization_enabled,
            'ai_suggestions_enabled' => $user->ai_suggestions_enabled,
            'locale' => $user->locale?->value,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function auditable(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'is_admin' => $user->is_admin,
            'email_verified' => $user->email_verified_at !== null,
        ];
    }
}
