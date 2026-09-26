<?php

use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\OAuth\ApproveHouseholdAuthorizationController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\RedirectToPreferredLocaleController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\SetLocaleFromUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

Route::get('/.well-known/apple-app-site-association', function (): JsonResponse {
    return response()->json([
        'applinks' => [
            'details' => [
                [
                    'appIDs' => ['WZ5F9GQL2A.no.handlelistaapp'],
                    'components' => [
                        ['/' => '/invitations/*'],
                    ],
                ],
            ],
        ],
    ]);
})->name('apple-app-site-association');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::get('/', RedirectToPreferredLocaleController::class)->name('home');
Route::get('/privacy', RedirectToPreferredLocaleController::class)->name('privacy');
Route::get('/support', RedirectToPreferredLocaleController::class)->name('support');

Route::prefix('{locale}')
    ->whereIn('locale', config('handlelista.locales'))
    ->middleware(SetLocaleFromUrl::class)
    ->name('localized.')
    ->group(function () {
        Route::get('/', [PublicPageController::class, 'home'])->name('home');
        Route::get('/privacy', [PublicPageController::class, 'privacy'])->name('privacy');
        Route::get('/support', [PublicPageController::class, 'support'])->name('support');
    });

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// Consent for third-party OAuth clients (MCP connectors): approve + pick a household.
Route::post('oauth/authorize/household', [ApproveHouseholdAuthorizationController::class, 'approve'])
    ->middleware('auth')
    ->name('oauth.household-authorizations.approve');

// "Continue with Google": sign in, sign up, or confirm the password of a
// passwordless account. The app's web sign-in sheet uses the same routes.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->name('social.redirect');
    Route::get('auth/{provider}/callback', [SocialiteController::class, 'callback'])->name('social.callback');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
