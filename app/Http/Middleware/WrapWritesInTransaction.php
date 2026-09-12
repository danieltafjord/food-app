<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Run every REST write (anything but GET/HEAD/OPTIONS) inside one transaction.
 *
 * Syncable models stamp a household version on every save; without an
 * enclosing transaction each save allocates (and commits) its own version
 * before the row itself is written, so a sync poll landing in between can
 * read the new version yet never see the row. One transaction per request
 * makes the version and every row it stamps visible atomically, and lets the
 * allocator share a single version across all saves in the request.
 *
 * The sync endpoint manages its own transaction (and an empty poll must not
 * open one at all), so it is skipped. An error response rolls the work back.
 */
class WrapWritesInTransaction
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->routeIs('api.v1.sync')) {
            return $next($request);
        }

        DB::beginTransaction();

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($response->getStatusCode() >= 400) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        return $response;
    }
}
