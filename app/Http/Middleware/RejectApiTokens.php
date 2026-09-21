<?php

namespace App\Http\Middleware;

use App\Models\ApiTokenDetail;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectApiTokens
{
    /**
     * The mobile API is reserved for the first-party app; user-created API
     * tokens must use the public API instead.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tokenId = $request->user()?->token()?->oauth_access_token_id;

        if ($tokenId && ApiTokenDetail::query()->where('token_id', $tokenId)->exists()) {
            abort(Response::HTTP_FORBIDDEN, 'API tokens cannot be used with this endpoint.');
        }

        return $next($request);
    }
}
