<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Households\RemoveMember;
use App\Actions\Households\UpdateMemberRole;
use App\Data\MemberData;
use App\Data\MemberRoleInputData;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\LaravelData\DataCollection;

class MemberController extends ApiController
{
    /**
     * List the members of the active household.
     */
    public function index(Request $request): DataCollection
    {
        $members = $this->currentHousehold($request)->members()->get()
            ->map(fn (User $user) => MemberData::fromUser($user));

        return MemberData::collect($members, DataCollection::class);
    }

    public function update(MemberRoleInputData $data, Request $request, User $user, UpdateMemberRole $action): Response
    {
        $household = $this->currentHousehold($request);
        $this->authorize('manage', $household);

        abort_unless($household->hasMember($user), 404);

        $action->handle($household, $user, $data->role);

        return response()->noContent();
    }

    public function destroy(Request $request, User $user, RemoveMember $action): Response
    {
        $household = $this->currentHousehold($request);

        // Owners may remove anyone; members may remove only themselves (leave).
        abort_unless(
            $household->isOwnedBy($request->user()) || $request->user()->is($user),
            403,
        );
        abort_unless($household->hasMember($user), 404);

        $action->handle($household, $user);

        return response()->noContent();
    }
}
