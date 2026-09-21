<?php

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

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
