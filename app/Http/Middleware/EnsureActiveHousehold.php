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

        // Membership straight off the pivot's (household_id, user_id) index —
        // no join through users.
        $locksHousehold = ! $request->isMethodSafe() && ! $request->routeIs('api.v1.sync', 'api.v1.ai.*');
        $household = $user?->currentHousehold()
            ->whereExists(fn ($query) => $query
                ->from('household_user')
                ->whereColumn('household_user.household_id', 'households.id')
                ->where('household_user.user_id', $user->id))
            ->when($locksHousehold, fn ($query) => $query->lockForUpdate())
            ->first();

        if (! $household || ($locksHousehold && ! $household->hasMember($user))) {
            abort(Response::HTTP_CONFLICT, 'No active household selected.');
        }

        $request->attributes->set('current_household', $household);

        return $next($request);
    }
}
