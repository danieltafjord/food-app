<?php

use App\Http\Controllers\Settings\AiAssistanceController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\ConnectedAppController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])
        ->middleware(RequirePassword::class)
        ->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('api-tokens.store');
    Route::delete('settings/api-tokens/{apiToken}', [ApiTokenController::class, 'destroy'])->name('api-tokens.destroy');
    Route::delete('settings/connected-apps/{grant}', [ConnectedAppController::class, 'destroy'])->name('connected-apps.destroy');

    Route::get('settings/ai', [AiAssistanceController::class, 'edit'])->name('ai-assistance.edit');
    Route::patch('settings/ai', [AiAssistanceController::class, 'update'])->name('ai-assistance.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
});
