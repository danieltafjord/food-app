<?php

namespace App\Actions\Households;

use App\Models\ApiTokenDetail;
use App\Models\Household;
use App\Models\OAuthHouseholdGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

class RemoveMember
{
    /**
     * Remove a member from the household (also used when a member leaves).
     */
    public function handle(Household $household, User $member): void
    {
        DB::transaction(function () use ($household, $member): void {
            // Serialise membership changes per household so two owners leaving at
            // once cannot both pass the last-owner check.
            Household::query()->lockForUpdate()->findOrFail($household->id);

            if ($household->isOwnedBy($member) && $household->ownerCount() <= 1) {
                abort(409, 'The household must have at least one owner.');
            }

            $household->members()->detach($member->id);

            // API tokens pinned to this household are useless to a non-member.
            Passport::token()->newQuery()
                ->where('user_id', $member->id)
                ->whereIn('id', ApiTokenDetail::query()->where('household_id', $household->id)->select('token_id'))
                ->update(['revoked' => true]);

            OAuthHouseholdGrant::query()
                ->where('user_id', $member->id)
                ->where('household_id', $household->id)
                ->delete();

            if ($member->current_household_id === $household->id) {
                $member->update(['current_household_id' => null]);
            }
        });
    }
}
