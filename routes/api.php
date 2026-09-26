<?php

use App\Http\Controllers\Api\Public\V1\MeController as PublicMeController;
use App\Http\Controllers\Api\Public\V1\TodayController;
use App\Http\Controllers\Api\V1\AiController;
use App\Http\Controllers\Api\V1\Auth\DeviceController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\DinnerCategoryController;
use App\Http\Controllers\Api\V1\DinnerController;
use App\Http\Controllers\Api\V1\DinnerImageController;
use App\Http\Controllers\Api\V1\DinnerPlanController;
use App\Http\Controllers\Api\V1\DinnerPlanEntryController;
use App\Http\Controllers\Api\V1\GenerateShoppingListController;
use App\Http\Controllers\Api\V1\HouseholdController;
use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\RealtimeController;
use App\Http\Controllers\Api\V1\ShoppingListController;
use App\Http\Controllers\Api\V1\ShoppingListItemController;
use App\Http\Controllers\Api\V1\SwitchHouseholdController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\WeekSuggestionController;
use Illuminate\Support\Facades\Route;

// Explicit first-use suggestions are available before registration. No household writes or body logging.
Route::post('v1/ai/plan-week', WeekSuggestionController::class)
    ->middleware('throttle:week-planning')->name('api.v1.ai.plan-week');

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['api.log:app', 'auth:api', 'api.app-only', 'throttle:api', 'api.transaction'])
    ->group(function () {
        // Authenticated user + token management
        Route::get('me', [MeController::class, 'show'])->name('me');
        Route::patch('me/settings', [MeController::class, 'updateSettings'])->name('me.settings.update');
        Route::patch('me/profile', [MeController::class, 'updateProfile'])->name('me.profile.update');
        Route::post('auth/logout', LogoutController::class)->name('auth.logout');
        Route::get('auth/devices', [DeviceController::class, 'index'])->name('auth.devices.index');
        Route::delete('auth/devices/{token}', [DeviceController::class, 'destroy'])->name('auth.devices.destroy');
        Route::get('realtime', RealtimeController::class)->name('realtime');

        // Push notifications: this install's token and the user's topic choices
        Route::put('me/push-token', [NotificationController::class, 'registerDevice'])->name('me.push-token.update');
        Route::delete('me/push-token', [NotificationController::class, 'unregisterDevice'])->name('me.push-token.destroy');
        Route::get('me/notifications', [NotificationController::class, 'preferences'])->name('me.notifications.show');
        Route::patch('me/notifications', [NotificationController::class, 'updatePreferences'])->name('me.notifications.update');

        // Households the user belongs to
        Route::get('households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('households', [HouseholdController::class, 'store'])->name('households.store');
        Route::get('households/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::patch('households/{household}', [HouseholdController::class, 'update'])->name('households.update');
        Route::delete('households/{household}', [HouseholdController::class, 'destroy'])->name('households.destroy');
        Route::post('household/switch', SwitchHouseholdController::class)->name('household.switch');
        Route::post('household/setup', [HouseholdController::class, 'setup'])->name('household.setup');

        // Invitations addressed to the authenticated user (bound by token)
        Route::post('invitations/{invitation:token}/accept', [InvitationAcceptanceController::class, 'accept'])->name('invitations.accept');
        Route::post('invitations/{invitation:token}/decline', [InvitationAcceptanceController::class, 'decline'])->name('invitations.decline');

        // Scoped to the active household
        Route::middleware('household.active')->group(function () {
            // Local-first mobile sync: one batched push/pull of all syncable data.
            Route::post('sync', [SyncController::class, 'store'])->middleware('throttle:first-sync')->name('sync');

            Route::get('household/members', [MemberController::class, 'index'])->name('household.members.index');
            Route::patch('household/members/{user}', [MemberController::class, 'update'])->name('household.members.update');
            Route::delete('household/members/{user}', [MemberController::class, 'destroy'])->name('household.members.destroy');

            Route::get('household/invitations', [InvitationController::class, 'index'])->name('household.invitations.index');
            Route::post('household/invitations', [InvitationController::class, 'store'])->middleware('throttle:invitations')->name('household.invitations.store');
            Route::delete('household/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('household.invitations.destroy');

            // Catalogue & recipes
            Route::apiResource('ingredients', IngredientController::class);
            Route::apiResource('dinners', DinnerController::class);
            Route::apiResource('dinner-categories', DinnerCategoryController::class)->parameters(['dinner-categories' => 'dinnerCategory'])->only(['index', 'store', 'update', 'destroy'])->whereUuid('dinnerCategory');

            // Dinner plans + their scheduled entries
            Route::get('dinner-plans', [DinnerPlanController::class, 'index'])->name('dinner-plans.index');
            Route::post('dinner-plans', [DinnerPlanController::class, 'store'])->name('dinner-plans.store');
            Route::get('dinner-plans/{dinnerPlan}', [DinnerPlanController::class, 'show'])->name('dinner-plans.show');
            Route::match(['put', 'patch'], 'dinner-plans/{dinnerPlan}', [DinnerPlanController::class, 'update'])->name('dinner-plans.update');
            Route::delete('dinner-plans/{dinnerPlan}', [DinnerPlanController::class, 'destroy'])->name('dinner-plans.destroy');

            Route::post('dinner-plans/{dinnerPlan}/entries', [DinnerPlanEntryController::class, 'store'])->name('dinner-plans.entries.store');
            Route::match(['put', 'patch'], 'dinner-plans/{dinnerPlan}/entries/{entry}', [DinnerPlanEntryController::class, 'update'])->name('dinner-plans.entries.update');
            Route::delete('dinner-plans/{dinnerPlan}/entries/{entry}', [DinnerPlanEntryController::class, 'destroy'])->name('dinner-plans.entries.destroy');

            // Generate a shopping list from a plan (servings-scaled + aggregated)
            Route::post('dinner-plans/{dinnerPlan}/shopping-list', GenerateShoppingListController::class)->name('dinner-plans.shopping-list.generate');

            // Shopping lists + their items
            Route::get('shopping-lists', [ShoppingListController::class, 'index'])->name('shopping-lists.index');
            Route::post('shopping-lists', [ShoppingListController::class, 'store'])->name('shopping-lists.store');
            Route::get('shopping-lists/{shoppingList}', [ShoppingListController::class, 'show'])->name('shopping-lists.show');
            Route::match(['put', 'patch'], 'shopping-lists/{shoppingList}', [ShoppingListController::class, 'update'])->name('shopping-lists.update');
            Route::delete('shopping-lists/{shoppingList}', [ShoppingListController::class, 'destroy'])->name('shopping-lists.destroy');

            Route::post('shopping-lists/{shoppingList}/items', [ShoppingListItemController::class, 'store'])->name('shopping-lists.items.store');
            Route::match(['put', 'patch'], 'shopping-lists/{shoppingList}/items/{item}', [ShoppingListItemController::class, 'update'])->name('shopping-lists.items.update');
            Route::patch('shopping-lists/{shoppingList}/items/{item}/check', [ShoppingListItemController::class, 'check'])->name('shopping-lists.items.check');
            Route::delete('shopping-lists/{shoppingList}/items/{item}', [ShoppingListItemController::class, 'destroy'])->name('shopping-lists.items.destroy');
        });
    });

/*
 * Public API for user-created API tokens (agents, Home Assistant, scripts).
 * Same actions and resources as the mobile API, but scoped to the household
 * the token is pinned to and limited to everyday household data.
 */
Route::prefix('public/v1')
    ->name('api.public.v1.')
    ->middleware(['api.log:public', 'auth:api', 'throttle:public-api', 'api.transaction', 'api.token'])
    ->group(function () {
        Route::get('me', PublicMeController::class)->name('me');
        Route::get('today', TodayController::class)->name('today');

        Route::apiResource('ingredients', IngredientController::class);
        Route::apiResource('dinners', DinnerController::class);
        Route::apiResource('dinner-categories', DinnerCategoryController::class)->parameters(['dinner-categories' => 'dinnerCategory'])->only(['index', 'store', 'update', 'destroy'])->whereUuid('dinnerCategory');

        Route::apiResource('dinner-plans', DinnerPlanController::class)->parameters(['dinner-plans' => 'dinnerPlan']);
        Route::apiResource('dinner-plans.entries', DinnerPlanEntryController::class)
            ->parameters(['dinner-plans' => 'dinnerPlan'])
            ->only(['store', 'update', 'destroy']);
        Route::post('dinner-plans/{dinnerPlan}/shopping-list', GenerateShoppingListController::class)->name('dinner-plans.shopping-list.generate');

        Route::apiResource('shopping-lists', ShoppingListController::class)->parameters(['shopping-lists' => 'shoppingList']);
        Route::apiResource('shopping-lists.items', ShoppingListItemController::class)
            ->parameters(['shopping-lists' => 'shoppingList'])
            ->only(['store', 'update', 'destroy']);
        Route::patch('shopping-lists/{shoppingList}/items/{item}/check', [ShoppingListItemController::class, 'check'])->name('shopping-lists.items.check');
    });

// AI calls never hold the API write transaction across provider requests.
Route::prefix('v1/ai')->name('api.v1.ai.')
    ->middleware(['api.log:app', 'auth:api', 'api.app-only', 'throttle:api', 'household.active'])
    ->group(function () {
        Route::get('settings', [AiController::class, 'settings'])->name('settings');
        Route::patch('settings', [AiController::class, 'updateSettings'])->name('settings.update');
        Route::post('categorize', [AiController::class, 'categorize'])->middleware('throttle:ai')->name('categorize');
        Route::post('suggest', [AiController::class, 'suggest'])->middleware('throttle:ai')->name('suggest');
    });

// Picture uploads and generation stay outside the write transaction: image
// processing and provider calls are slow, and they only add files.
Route::prefix('v1/dinner-images')->name('api.v1.dinner-images.')
    ->middleware(['api.log:app', 'auth:api', 'api.app-only', 'throttle:api', 'household.active'])
    ->group(function () {
        Route::post('/', [DinnerImageController::class, 'store'])->middleware('throttle:dinner-images')->name('store');
        Route::post('generate', [DinnerImageController::class, 'generate'])->middleware('throttle:ai')->name('generate');
    });

// Deleting the account from the app talks to Apple, so it stays outside the
// write transaction; DeleteAccount runs its own.
Route::delete('v1/me', [MeController::class, 'destroy'])
    ->middleware(['api.log:app', 'auth:api', 'api.app-only', 'throttle:api'])
    ->name('api.v1.me.destroy');
