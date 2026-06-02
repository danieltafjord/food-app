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

    public function store(InvitationInputData $data, Request $request, InviteMember $action): InvitationData
    {
        $household = $this->currentHousehold($request);
        $this->authorize('manage', $household);

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
