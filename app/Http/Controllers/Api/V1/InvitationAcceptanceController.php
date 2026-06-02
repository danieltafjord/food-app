<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Households\AcceptInvitation;
use App\Actions\Households\DeclineInvitation;
use App\Data\HouseholdData;
use App\Http\Controllers\Controller;
use App\Models\HouseholdInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class InvitationAcceptanceController extends Controller
{
    /**
     * Accept an invitation addressed to the authenticated user (bound by token).
     */
    public function accept(Request $request, HouseholdInvitation $invitation, AcceptInvitation $action): HouseholdData
    {
        return HouseholdData::from($action->handle($invitation, $request->user()));
    }

    public function decline(Request $request, HouseholdInvitation $invitation, DeclineInvitation $action): JsonResponse
    {
        abort_unless(
            Str::lower($invitation->email) === Str::lower($request->user()->email),
            403,
        );

        $action->handle($invitation);

        return response()->json(['message' => 'Invitation declined.']);
    }
}
