<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveHousehold
{
    /**
     * Resolve the user's active household and reject the request if they have
     * none selected or are no longer a member of it. The validated household
     * is stashed on the request for controllers to read.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $household = $user?->currentHousehold()
            ->whereHas('members', fn ($query) => $query->whereKey($user->id))
            ->first();

        if (! $household) {
            abort(Response::HTTP_CONFLICT, 'No active household selected.');
        }

        $request->attributes->set('current_household', $household);

        return $next($request);
    }
}
