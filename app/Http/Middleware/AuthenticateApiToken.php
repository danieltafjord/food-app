<?php

namespace App\Http\Middleware;

use App\Enums\ApiTokenScope;
use App\Models\ApiTokenDetail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    /**
     * Only user-created API tokens may call the public API. The household the
     * token is pinned to becomes the request's active household, provided the
     * user is still a member of it. Safe requests need the "read" scope and
     * everything else needs "write" — except in "tools" mode (the MCP server,
     * where every call is a POST), which leaves scope checks to each tool.
     */
    public function handle(Request $request, Closure $next, string $scopeMode = 'method'): Response
    {
        $user = $request->user();
        $tokenId = $user?->token()?->oauth_access_token_id;

        $apiToken = $tokenId
            ? ApiTokenDetail::query()->where('token_id', $tokenId)->first()
            : null;

        if (! $apiToken) {
            abort(Response::HTTP_FORBIDDEN, 'This endpoint requires an API token.');
        }

        // Writes lock the household row, like the mobile API, so membership
        // cannot change underneath a request that is mutating shared data.
        $household = $apiToken->household()
            ->when(! $request->isMethodSafe(), fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $household?->hasMember($user)) {
            abort(Response::HTTP_FORBIDDEN, 'This token no longer has access to its household.');
        }

        $requiredScope = $request->isMethodSafe() ? ApiTokenScope::Read : ApiTokenScope::Write;

        if ($scopeMode === 'method' && ! $apiToken->allows($requiredScope)) {
            abort(Response::HTTP_FORBIDDEN, "This token is missing the \"{$requiredScope->value}\" permission.");
        }

        // Minute precision is plenty for "last used", and spares a write per call.
        if ($apiToken->last_used_at === null || $apiToken->last_used_at->lt(now()->subMinute())) {
            $apiToken->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('current_household', $household);
        $request->attributes->set('api_token', $apiToken);

        return $next($request);
    }
}
