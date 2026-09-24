<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureActiveHousehold;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogApiRequest;
use App\Http\Middleware\RejectApiTokens;
use App\Http\Middleware\WrapWritesInTransaction;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Mobile devices authorize live-sync channels with their API token.
    ->withBroadcasting(__DIR__.'/../routes/channels.php', [
        'prefix' => 'api/v1',
        'middleware' => ['api', 'auth:api', 'api.app-only', 'throttle:api'],
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating proxy/load balancer: keep generated links on
        // https and rate-limit by the real client IP, not the proxy's.
        $middleware->trustProxies(at: '*');

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->api(append: [
            EnsureUserIsActive::class.':api',
        ]);

        // Log API requests before authentication so rejected calls (401, 403,
        // 429) are captured too; the priority list would otherwise move
        // `auth` ahead of the logger.
        $middleware->prependToPriorityList(AuthenticatesRequests::class, LogApiRequest::class);

        $middleware->alias([
            'household.active' => EnsureActiveHousehold::class,
            'api.transaction' => WrapWritesInTransaction::class,
            'api.token' => AuthenticateApiToken::class,
            'api.log' => LogApiRequest::class,
            'api.app-only' => RejectApiTokens::class,
            'admin' => EnsureUserIsAdmin::class,
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'mcp') || ($request->is('admin/ai/test') && $request->expectsJson()),
        );
    })->create();
