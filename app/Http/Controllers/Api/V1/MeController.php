<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Users\UpdateUserSettings;
use App\Data\HouseholdData;
use App\Data\UserData;
use App\Data\UserSettingsData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /**
     * Return the authenticated user and their active household.
     */
    public function show(Request $request): UserData
    {
        return $this->toData($request->user());
    }

    /**
     * Update the authenticated user's app settings (theme + language).
     */
    public function updateSettings(UserSettingsData $data, Request $request, UpdateUserSettings $action): UserData
    {
        return $this->toData($action->handle($request->user(), $data));
    }

    private function toData(User $user): UserData
    {
        $user->loadMissing('currentHousehold');

        return new UserData(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            emailVerified: $user->hasVerifiedEmail(),
            twoFactorEnabled: ! is_null($user->two_factor_confirmed_at),
            theme: $user->theme,
            locale: $user->locale,
            currentHousehold: $user->currentHousehold
                ? HouseholdData::from($user->currentHousehold)
                : null,
        );
    }
}
