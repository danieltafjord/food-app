<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\SocialProvider;
use App\Http\Responses\LoginResponse;
use App\Http\Responses\RegisterResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Deliver the OAuth post-login/registration redirect as a full-page
        // Inertia visit so the browser can follow it out to the app's
        // custom-scheme callback. See App\Http\Responses\RedirectsToOAuthAuthorize.
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // Same as the default password check, but deactivated accounts get a clear refusal.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->input(Fortify::username()))->first();

            if (! $user || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }

            if ($user->deactivated_at) {
                throw ValidationException::withMessages([Fortify::username() => __('This account has been deactivated.')]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => $this->redirectToRequestedProvider($request)
            ?? Inertia::render('auth/Login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'status' => $request->session()->get('status'),
                'socialProviders' => $this->webSocialProviders(),
            ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            'socialProviders' => $this->webSocialProviders(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        // Someone without a password confirms with a provider they signed up with.
        Fortify::confirmPasswordView(fn (Request $request) => Inertia::render('auth/ConfirmPassword', [
            'hasPassword' => $request->user()->hasPassword(),
            'socialProviders' => array_values(array_intersect(
                $this->webSocialProviders(),
                $request->user()->socialAccounts()->pluck('provider')->map->value->all(),
            )),
        ]));
    }

    /**
     * @return list<string>
     */
    private function webSocialProviders(): array
    {
        return array_map(fn (SocialProvider $provider): string => $provider->value, SocialProvider::availableOnWeb());
    }

    /**
     * The app's "Continue with Google" opens the OAuth authorize URL with
     * `provider=google`. When that lands on the login page, go straight on to
     * Google instead of showing the form. The hint is dropped from the saved
     * URL first, so cancelling at Google comes back to the normal form.
     */
    private function redirectToRequestedProvider(Request $request): ?RedirectResponse
    {
        $intended = $request->session()->get('url.intended');

        if (! is_string($intended) || ! str_contains($intended, '/oauth/authorize')) {
            return null;
        }

        parse_str((string) parse_url($intended, PHP_URL_QUERY), $query);
        $provider = SocialProvider::tryFrom((string) ($query['provider'] ?? ''));

        if (! $provider || ! in_array($provider, SocialProvider::availableOnWeb(), true)) {
            return null;
        }

        unset($query['provider']);
        $request->session()->put('url.intended', strtok($intended, '?').'?'.http_build_query($query));

        return to_route('social.redirect', $provider);
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            return Limit::perMinute(10)->by(
                ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });
    }
}
