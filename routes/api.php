<?php

use App\Http\Controllers\Api\V1\Auth\DeviceController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\DinnerController;
use App\Http\Controllers\Api\V1\DinnerPlanController;
use App\Http\Controllers\Api\V1\DinnerPlanEntryController;
use App\Http\Controllers\Api\V1\GenerateShoppingListController;
use App\Http\Controllers\Api\V1\HouseholdController;
use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\ShoppingListController;
use App\Http\Controllers\Api\V1\ShoppingListItemController;
use App\Http\Controllers\Api\V1\SwitchHouseholdController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->middleware(['auth:api', 'throttle:api'])
    ->group(function () {
        // Authenticated user + token management
        Route::get('me', [MeController::class, 'show'])->name('me');
        Route::patch('me/settings', [MeController::class, 'updateSettings'])->name('me.settings.update');
        Route::post('auth/logout', LogoutController::class)->name('auth.logout');
        Route::get('auth/devices', [DeviceController::class, 'index'])->name('auth.devices.index');
        Route::delete('auth/devices/{token}', [DeviceController::class, 'destroy'])->name('auth.devices.destroy');

        // Households the user belongs to
        Route::get('households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('households', [HouseholdController::class, 'store'])->name('households.store');
        Route::get('households/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::patch('households/{household}', [HouseholdController::class, 'update'])->name('households.update');
        Route::delete('households/{household}', [HouseholdController::class, 'destroy'])->name('households.destroy');
        Route::post('household/switch', SwitchHouseholdController::class)->name('household.switch');

        // Invitations addressed to the authenticated user (bound by token)
        Route::post('invitations/{invitation:token}/accept', [InvitationAcceptanceController::class, 'accept'])->name('invitations.accept');
        Route::post('invitations/{invitation:token}/decline', [InvitationAcceptanceController::class, 'decline'])->name('invitations.decline');

        // Scoped to the active household
        Route::middleware('household.active')->group(function () {
            // Local-first mobile sync: one batched push/pull of all syncable data.
            Route::post('sync', [SyncController::class, 'store'])->name('sync');

            Route::get('household/members', [MemberController::class, 'index'])->name('household.members.index');
            Route::patch('household/members/{user}', [MemberController::class, 'update'])->name('household.members.update');
            Route::delete('household/members/{user}', [MemberController::class, 'destroy'])->name('household.members.destroy');

            Route::get('household/invitations', [InvitationController::class, 'index'])->name('household.invitations.index');
            Route::post('household/invitations', [InvitationController::class, 'store'])->name('household.invitations.store');
            Route::delete('household/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('household.invitations.destroy');

            // Catalogue & recipes
            Route::apiResource('ingredients', IngredientController::class);
            Route::apiResource('dinners', DinnerController::class);

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
