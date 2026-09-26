<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\AppleTokens;
use App\Actions\Auth\VerifyAppleIdentityToken;
use App\Actions\Users\DeleteAccount;
use App\Actions\Users\UpdateUserSettings;
use App\Data\HouseholdData;
use App\Data\UserData;
use App\Data\UserSettingsData;
use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

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

    /**
     * Delete the account from the iOS app, confirmed by signing in with Apple
     * again. People who signed up with Apple have no password and cannot sign
     * in on the website, where everyone else deletes their account.
     */
    public function destroy(Request $request, VerifyAppleIdentityToken $verifyIdentityToken, AppleTokens $appleTokens, DeleteAccount $deleteAccount): Response
    {
        $input = $request->validate([
            'identity_token' => ['required', 'string'],
            'nonce' => ['required', 'string'],
            'authorization_code' => ['nullable', 'string'],
        ]);
        $user = $request->user();

        $identity = $verifyIdentityToken->handle($input['identity_token'], $input['nonce']);
        $appleAccount = $user->socialAccounts()
            ->where('provider', SocialProvider::Apple)
            ->where('provider_user_id', $identity['sub'])
            ->first() ?? throw ValidationException::withMessages([
                'identity_token' => __('Sign in with the Apple ID that is connected to this account.'),
            ]);

        // A fresh code guarantees there is something to revoke at Apple.
        if (filled($input['authorization_code'] ?? null) && ($refreshToken = $appleTokens->exchange($input['authorization_code']))) {
            $appleAccount->update(['refresh_token' => $refreshToken]);
        }

        $deleteAccount->handle($user);

        return response()->noContent();
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
            signInProviders: $user->socialAccounts()->pluck('provider')->map->value->values()->all(),
            theme: $user->theme,
            locale: $user->locale,
            currentHousehold: $user->currentHousehold
                ? HouseholdData::from($user->currentHousehold)
                : null,
        );
    }
}
