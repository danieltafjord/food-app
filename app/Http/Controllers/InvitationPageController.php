<?php

namespace App\Http\Controllers;

use App\Models\HouseholdInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class InvitationPageController extends Controller
{
    /**
     * The web fallback for an invitation link when the app did not open it:
     * explain the invitation and offer to open it in the app or install the
     * app. Anyone can load it, so it names the household only while the
     * invitation can still be accepted, and never who sent it or to whom.
     */
    public function __invoke(Request $request, string $locale, string $token): Response
    {
        $invitation = HouseholdInvitation::query()->with('household')->where('token', $token)->first();

        return Inertia::render('Invitation', [
            'householdName' => $invitation?->isPending() ? $invitation->household->name : null,
            'appUrl' => 'foodapp://invitations/'.rawurlencode($token),
            'appStoreUrl' => config('services.app_store_url') ?: null,
        ])->toResponse($request)->withHeaders([
            // The token is in the URL; keep it out of Referer headers and caches.
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
