<?php

use App\Http\Controllers\Api\V1\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api/v1/auth')->name('api.v1.auth.')->group(function (): void {
    Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:auth.oauth')
        ->block(10, 10)
        ->name('google.redirect');
    Route::get('google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:auth.oauth')
        ->block(10, 10)
        ->name('google.callback');
});
