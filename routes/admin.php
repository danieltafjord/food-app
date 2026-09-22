<?php

use App\Http\Controllers\Admin\AiSettingsController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::redirect('/', '/admin/analytics');

    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics');
    Route::get('ai', [AiSettingsController::class, 'edit'])->name('ai.edit');
    Route::patch('ai', [AiSettingsController::class, 'update'])->name('ai.update');
    Route::patch('ai/limits', [AiSettingsController::class, 'updateLimits'])->name('ai.limits.update');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    Route::patch('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});
