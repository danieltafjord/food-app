<?php

namespace App\Actions\Auth;

use App\Enums\SocialProvider;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResolveSocialUser
{
    /**
     * Find the user for a provider identity, linking or creating one on first
     * sign-in.
     *
     * An identity already linked signs straight in. Otherwise it joins the
     * account with the same email, but only when the provider vouches for the
     * address and that account has verified it too: an unverified account could
     * have been registered by someone else in advance, holding a password the
     * real owner does not know. Anyone else gets a new account without a password.
     *
     * @throws ValidationException when the identity cannot sign in
     */
    public function handle(
        SocialProvider $provider,
        string $providerUserId,
        ?string $email,
        bool $emailVerified,
        ?string $name = null,
    ): User {
        $email = filled($email) ? mb_strtolower(trim($email)) : null;

        try {
            $user = DB::transaction(fn (): User => $this->findOrCreate($provider, $providerUserId, $email, $emailVerified, $name));
        } catch (UniqueConstraintViolationException) {
            // Two first sign-ins raced; the other one linked or created the account.
            $user = $this->linkedUser($provider, $providerUserId)
                ?? throw ValidationException::withMessages(['email' => __('Sign-in failed. Please try again.')]);
        }

        if ($user->deactivated_at) {
            throw ValidationException::withMessages(['email' => __('This account has been deactivated.')]);
        }

        return $user;
    }

    private function findOrCreate(SocialProvider $provider, string $providerUserId, ?string $email, bool $emailVerified, ?string $name): User
    {
        if ($user = $this->linkedUser($provider, $providerUserId)) {
            return $user;
        }

        if ($email === null || ! $emailVerified) {
            throw ValidationException::withMessages([
                'email' => __(':provider did not share a verified email address.', ['provider' => $provider->label()]),
            ]);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user && ! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => __('An account with this email already exists. Log in with your password and verify your email first; after that you can use :provider.', ['provider' => $provider->label()]),
            ]);
        }

        if ($user && $user->socialAccounts()->where('provider', $provider)->exists()) {
            throw ValidationException::withMessages([
                'email' => __('This account is already connected to a different :provider account.', ['provider' => $provider->label()]),
            ]);
        }

        if (! $user) {
            $user = User::create([
                'name' => filled($name) ? Str::limit(trim($name), 255, '') : Str::before($email, '@'),
                'email' => $email,
                'password' => null,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->socialAccounts()->create([
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'email' => $email,
        ]);

        return $user;
    }

    private function linkedUser(SocialProvider $provider, string $providerUserId): ?User
    {
        return SocialAccount::query()
            ->with('user')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first()
            ?->user;
    }
}
