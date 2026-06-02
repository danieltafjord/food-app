<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\HouseholdData;
use App\Data\UserData;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Return the authenticated user and their active household.
     */
    public function show(Request $request): UserData
    {
        $user = $request->user();
        $user->loadMissing('currentHousehold');

        return new UserData(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            emailVerified: $user->hasVerifiedEmail(),
            twoFactorEnabled: ! is_null($user->two_factor_confirmed_at),
            currentHousehold: $user->currentHousehold
                ? HouseholdData::from($user->currentHousehold)
                : null,
        );
    }
}
