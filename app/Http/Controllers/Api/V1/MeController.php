<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\AppleTokens;
use App\Actions\Auth\VerifyAppleIdentityToken;
use App\Actions\Users\DeleteAccount;
use App\Actions\Users\UpdateUserProfile;
use App\Actions\Users\UpdateUserSettings;
use App\Data\HouseholdData;
use App\Data\UserData;
use App\Data\UserProfileData;
use App\Data\UserSettingsData;
use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
     * Set the user's display name.
     */
    public function updateProfile(UserProfileData $data, Request $request, UpdateUserProfile $action): UserData
    {
        return $this->toData($action->handle($request->user(), $data));
    }

    /**
     * Delete the account from the app. The person proves it is them with
     * whatever their account has: signing in with Apple again, their
     * password, or (with neither) by typing their account's email address.
     */
    public function destroy(Request $request, VerifyAppleIdentityToken $verifyIdentityToken, AppleTokens $appleTokens, DeleteAccount $deleteAccount): Response
    {
        $input = $request->validate([
            'identity_token' => ['nullable', 'string'],
            'nonce' => ['required_with:identity_token', 'nullable', 'string'],
            'authorization_code' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'email' => ['nullable', 'string'],
        ]);
        $user = $request->user();

        if (filled($input['identity_token'] ?? null)) {
            $this->confirmWithApple($user, $input, $verifyIdentityToken, $appleTokens);
        } elseif ($user->hasPassword()) {
            $this->confirmWithPassword($user, $input['password'] ?? null);
        } else {
            $this->confirmWithEmail($user, $input['email'] ?? null);
        }

        $deleteAccount->handle($user);

        return response()->noContent();
    }

    /**
     * @param  array{identity_token: string, nonce: string, authorization_code?: ?string}  $input
     *
     * @throws ValidationException
     */
    private function confirmWithApple(User $user, array $input, VerifyAppleIdentityToken $verifyIdentityToken, AppleTokens $appleTokens): void
    {
        $identity = $verifyIdentityToken->handle($input['identity_token'], $input['nonce']);
        $appleAccount = $user->socialAccounts()
            ->where('provider', SocialProvider::Apple)
            ->where('provider_user_id', $identity['sub'])
            ->first() ?? throw ValidationException::withMessages([
                'identity_token' => __('account.apple_account_mismatch'),
            ]);

        // A fresh code guarantees there is something to revoke at Apple.
        if (filled($input['authorization_code'] ?? null) && ($refreshToken = $appleTokens->exchange($input['authorization_code']))) {
            $appleAccount->update(['refresh_token' => $refreshToken]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function confirmWithPassword(User $user, ?string $password): void
    {
        if (blank($password)) {
            throw ValidationException::withMessages(['password' => __('account.delete_password_required')]);
        }

        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['password' => __('account.delete_password_incorrect')]);
        }
    }

    /**
     * Without a password or Apple ID to check (a Google-only account), typing
     * the account's email address confirms the deletion is intended.
     *
     * @throws ValidationException
     */
    private function confirmWithEmail(User $user, ?string $email): void
    {
        if (blank($email)) {
            throw ValidationException::withMessages(['email' => __('account.delete_email_required')]);
        }

        if (Str::lower(trim($email)) !== Str::lower($user->email)) {
            throw ValidationException::withMessages(['email' => __('account.delete_email_mismatch')]);
        }
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
            hasPassword: $user->hasPassword(),
            needsName: $user->needs_name,
            signInProviders: $user->socialAccounts()->pluck('provider')->map->value->values()->all(),
            theme: $user->theme,
            locale: $user->locale,
            currentHousehold: $user->currentHousehold
                ? HouseholdData::from($user->currentHousehold)
                : null,
        );
    }
}
