<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ResolveSocialUser;
use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

class SocialiteController extends Controller
{
    /**
     * Send the browser to the provider. `?confirm=1` from a signed-in user
     * re-checks their identity in place of a password confirmation.
     */
    public function redirect(Request $request, SocialProvider $provider): SymfonyRedirectResponse
    {
        $this->ensureAvailable($provider);

        $request->session()->put('social.confirming', $request->boolean('confirm') && $request->user() !== null);

        return Socialite::driver($provider->value)->redirect();
    }

    /**
     * Sign the person in (linking or creating their account), or finish a
     * password confirmation, then continue where they were headed; for the
     * mobile app that is the OAuth authorize endpoint and back into the app.
     */
    public function callback(Request $request, SocialProvider $provider, ResolveSocialUser $resolveUser): RedirectResponse
    {
        $this->ensureAvailable($provider);

        $confirming = $request->session()->pull('social.confirming', false) && $request->user() !== null;
        $returnTo = $confirming ? route('password.confirm') : route('login');

        // The person cancelled at the provider.
        if ($request->filled('error')) {
            return redirect($returnTo);
        }

        try {
            $identity = Socialite::driver($provider->value)->user();
        } catch (Throwable $exception) {
            report($exception);

            return redirect($returnTo)->withErrors([
                'social' => __(':provider sign-in failed. Please try again.', ['provider' => $provider->label()]),
            ]);
        }

        if ($confirming) {
            return $this->confirm($request, $provider, $identity);
        }

        try {
            $user = $resolveUser->handle(
                $provider,
                (string) $identity->getId(),
                $identity->getEmail(),
                $this->emailVerified($identity),
                $identity->getName(),
            );
        } catch (ValidationException $exception) {
            return redirect($returnTo)->withErrors(['social' => collect($exception->errors())->flatten()->first()]);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        // Signing in just now proves who they are as well as a password would.
        $request->session()->passwordConfirmed();

        return redirect()->intended(Fortify::redirects('login'));
    }

    /**
     * A password confirmation for someone signed in: the provider identity must
     * already belong to them. It never links or creates an account.
     */
    private function confirm(Request $request, SocialProvider $provider, ProviderUser $identity): RedirectResponse
    {
        $belongsToUser = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', (string) $identity->getId())
            ->where('user_id', $request->user()->id)
            ->exists();

        if (! $belongsToUser) {
            return to_route('password.confirm')->withErrors([
                'social' => __('That :provider account is not connected to your account.', ['provider' => $provider->label()]),
            ]);
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(Fortify::redirects('password-confirmation'));
    }

    private function emailVerified(ProviderUser $identity): bool
    {
        $raw = method_exists($identity, 'getRaw') ? $identity->getRaw() : [];

        return filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }

    private function ensureAvailable(SocialProvider $provider): void
    {
        abort_unless(in_array($provider, SocialProvider::availableOnWeb(), true), 404);
    }
}
