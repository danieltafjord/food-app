<?php

namespace App\Providers;

use App\Actions\Sync\AllocateSyncVersion;
use App\Enums\ApiTokenScope;
use App\Models\Passport\Client;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One allocator per request so writes inside one transaction share a version.
        $this->app->singleton(AllocateSyncVersion::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        $this->configureDefaults();
        $this->configurePassport();
        $this->configureApiDocs();
        $this->configureRateLimiting();
        $this->configureSyncVersions();
    }

    /**
     * A rolled-back transaction never committed its allocated sync version, so
     * the allocator must not hand that number out again as if it had.
     */
    protected function configureSyncVersions(): void
    {
        Event::listen(TransactionRolledBack::class, function (): void {
            $this->app->make(AllocateSyncVersion::class)->forget();
        });
    }

    /**
     * Configure the API rate limiter (per-user, falling back to IP).
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));

        // Each API token gets its own budget so an integration cannot starve
        // the mobile app (or another integration) of requests.
        RateLimiter::for('public-api', fn (Request $request) => Limit::perMinute(60)
            ->by('token:'.($request->user()?->token()?->oauth_access_token_id ?: $request->ip())));
    }

    /**
     * The OpenAPI docs (/docs/api) cover the public API only, which is
     * authenticated with a user-created bearer token.
     */
    protected function configureApiDocs(): void
    {
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });
    }

    /**
     * Configure Laravel Passport for the first-party mobile API.
     */
    protected function configurePassport(): void
    {
        Passport::useClientModel(Client::class);

        Passport::tokensExpireIn(CarbonInterval::days(15));
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::personalAccessTokensExpireIn(CarbonInterval::year());

        // routes/ai.php may already have registered MCP's scope; keep it.
        Passport::tokensCan([...Passport::$scopes, ...ApiTokenScope::descriptions()]);

        // Binds the AuthorizationViewResponse contract that the /oauth/authorize
        // controller resolves. First-party clients skip the consent screen, but
        // the binding must exist for the controller to be instantiated at all.
        Passport::authorizationView('oauth.authorize');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
