<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ApiTokens\CreateApiToken;
use App\Actions\ApiTokens\ListApiTokens;
use App\Actions\ApiTokens\RevokeApiToken;
use App\Data\ApiTokenData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApiTokenStoreRequest;
use App\Models\ApiTokenDetail;
use App\Models\Household;
use App\Models\OAuthHouseholdGrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApiTokenController extends Controller
{
    /**
     * Show the user's API tokens.
     */
    public function index(Request $request, ListApiTokens $action): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'tokens' => $action->handle($request->user())
                ->map(fn (ApiTokenDetail $apiToken) => ApiTokenData::fromApiToken($apiToken)->toArray())
                ->all(),
            'connectedApps' => OAuthHouseholdGrant::query()
                ->with(['client:id,name', 'household:id,name'])
                ->where('user_id', $request->user()->id)
                ->latest('id')
                ->get()
                ->map(fn (OAuthHouseholdGrant $grant) => [
                    'id' => $grant->id,
                    'name' => $grant->client?->name,
                    'household_name' => $grant->household?->name,
                    'can_write' => $grant->can_write,
                    'connected_at' => $grant->created_at?->toIso8601String(),
                ])
                ->all(),
            'households' => $request->user()->households()
                ->orderBy('name')
                ->get(['households.id', 'households.name'])
                ->map(fn (Household $household) => ['id' => $household->id, 'name' => $household->name])
                ->all(),
            'apiBaseUrl' => url('/api/public/v1'),
            'apiDocsUrl' => route('scramble.docs.ui'),
            'mcpUrl' => url('/mcp'),
        ]);
    }

    /**
     * Create a token and flash its plain-text value, which is shown only once.
     */
    public function store(ApiTokenStoreRequest $request, CreateApiToken $action): RedirectResponse
    {
        $result = $action->handle(
            $request->user(),
            Household::query()->findOrFail($request->integer('household_id')),
            $request->string('name')->trim()->value(),
            $request->boolean('can_write'),
        );

        Inertia::flash('plainTextApiToken', $result->accessToken);

        return to_route('api-tokens.index');
    }

    /**
     * Revoke one of the user's tokens.
     */
    public function destroy(Request $request, ApiTokenDetail $apiToken, RevokeApiToken $action): RedirectResponse
    {
        abort_unless($apiToken->token?->user_id === $request->user()->id, 404);

        $action->handle($apiToken);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('API token revoked.')]);

        return to_route('api-tokens.index');
    }
}
