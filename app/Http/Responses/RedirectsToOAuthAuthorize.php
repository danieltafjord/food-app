<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivers Fortify's post-authentication redirect as a full-page Inertia visit
 * when it targets the OAuth authorize endpoint.
 *
 * The Expo app signs in by opening `/oauth/authorize`, which (when logged out)
 * sends the user to the Inertia login page. That form submits over XHR, so the
 * Inertia client cannot follow the authorize endpoint's subsequent
 * custom-scheme redirect (`foodapp://oauth/callback`) — the browser would be
 * left stranded on the SPA (the dashboard) and the app would never receive its
 * authorization code. `Inertia::location()` responds with `X-Inertia-Location`,
 * which the client turns into a real browser navigation, restoring the redirect
 * chain back to the app.
 */
trait RedirectsToOAuthAuthorize
{
    protected function intendedResponse(Request $request, string $redirectKey): Response
    {
        $redirect = redirect()->intended(Fortify::redirects($redirectKey));

        if ($request->header('X-Inertia') && str_contains($redirect->getTargetUrl(), '/oauth/authorize')) {
            return Inertia::location($redirect->getTargetUrl());
        }

        return $redirect;
    }
}
