<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Households\AcceptInvitation;
use App\Actions\Households\DeclineInvitation;
use App\Data\HouseholdData;
use App\Http\Controllers\Controller;
use App\Models\HouseholdInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationAcceptanceController extends Controller
{
    /**
     * Accept an invitation (bound by token). The token was mailed to the
     * invited address, so holding it is the proof: the account may use a
     * different email, like an Apple "Hide My Email" relay address.
     */
    public function accept(Request $request, HouseholdInvitation $invitation, AcceptInvitation $action): HouseholdData
    {
        abort_unless($request->user()->hasVerifiedEmail(), 403, __('households.verify_email_to_respond'));

        return HouseholdData::from($action->handle($invitation, $request->user()));
    }

    /**
     * Decline an invitation (bound by token), on the same terms as accepting.
     */
    public function decline(Request $request, HouseholdInvitation $invitation, DeclineInvitation $action): JsonResponse
    {
        abort_unless($request->user()->hasVerifiedEmail(), 403, __('households.verify_email_to_respond'));

        $action->handle($invitation);

        return response()->json(['message' => __('households.invitation_declined')]);
    }
}
