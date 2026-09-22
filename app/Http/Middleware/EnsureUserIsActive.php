<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locks deactivated accounts out of every entry point. Deactivation also
 * revokes tokens and sessions, so this is the safety net for anything that
 * survived (for example a passkey login, which bypasses the password check).
 */
class EnsureUserIsActive
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $user = $request->user($guard);

        if (! $user?->deactivated_at) {
            return $next($request);
        }

        if ($guard === 'api' || $request->expectsJson()) {
            abort(response()->json(['message' => 'This account has been deactivated.', 'code' => 'deactivated'], Response::HTTP_FORBIDDEN));
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => __('This account has been deactivated.')]);
    }
}
