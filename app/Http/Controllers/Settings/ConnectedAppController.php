<?php

namespace App\Http\Controllers\Settings;

use App\Actions\ApiTokens\RevokeOAuthGrant;
use App\Http\Controllers\Controller;
use App\Models\OAuthHouseholdGrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ConnectedAppController extends Controller
{
    /**
     * Disconnect an app the user previously authorized via OAuth.
     */
    public function destroy(Request $request, OAuthHouseholdGrant $grant, RevokeOAuthGrant $action): RedirectResponse
    {
        abort_unless($grant->user_id === $request->user()->id, 404);

        $action->handle($grant);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('App disconnected.')]);

        return to_route('api-tokens.index');
    }
}
