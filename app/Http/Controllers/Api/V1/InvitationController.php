<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Households\InviteMember;
use App\Actions\Households\RevokeInvitation;
use App\Data\InvitationData;
use App\Data\InvitationInputData;
use App\Models\HouseholdInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class InvitationController extends ApiController
{
    /**
     * List the active household's invitations.
     */
    public function index(Request $request): DataCollection
    {
        $household = $this->currentHousehold($request);
        $this->authorize('manage', $household);

        $invitations = $household->invitations()->latest()->get()
            ->map(fn (HouseholdInvitation $invitation) => InvitationData::fromInvitation($invitation));

        return InvitationData::collect($invitations, DataCollection::class);
    }

    /**
     * Invite someone by email. Only people who have verified their own
     * address may send invitations, which keeps throwaway accounts from
     * using them to send mail.
     */
    public function store(InvitationInputData $data, Request $request, InviteMember $action): InvitationData
    {
        $household = $this->currentHousehold($request);
        $this->authorize('manage', $household);
        abort_unless($request->user()->hasVerifiedEmail(), 403, __('households.verify_email_to_invite'));

        return InvitationData::fromInvitation(
            $action->handle($household, $request->user(), $data),
        );
    }

    public function destroy(Request $request, HouseholdInvitation $invitation, RevokeInvitation $action): Response
    {
        $household = $this->currentHousehold($request);
        $this->authorize('manage', $household);

        abort_unless($invitation->household_id === $household->id, 404);

        $action->handle($invitation);

        return response()->noContent();
    }
}
