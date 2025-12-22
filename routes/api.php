<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerPointController;
use App\Http\Controllers\Api\V1\ExchangeController;
use App\Http\Controllers\Api\V1\PointController;
use App\Http\Controllers\Api\V1\ProviderController;
use App\Http\Controllers\Api\V1\VendorAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| API V1 Routes
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function (): void {
    // Authentication endpoints (public - no auth required)
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Vendor OTP authentication (public - no auth required)
    Route::prefix('vendor/auth')->group(function (): void {
        Route::post('/request-otp', [VendorAuthController::class, 'requestOtp']);
        Route::post('/verify-otp', [VendorAuthController::class, 'verifyOtp']);
        Route::post('/resend-otp', [VendorAuthController::class, 'resendOtp']);
    });

    // Public provider listing
    Route::get('/providers', [ProviderController::class, 'index']);
    Route::get('/providers/{provider}', [ProviderController::class, 'show']);

    // Authenticated routes
    Route::middleware('auth:sanctum')->group(function (): void {
        // Auth management
        Route::get('/auth/user', [AuthController::class, 'user']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout-all', [AuthController::class, 'logoutAll']);

        // Customer self-service endpoints (authenticated user's own data)
        Route::get('/points/balance', [PointController::class, 'balance']);
        Route::get('/points/transactions', [PointController::class, 'transactions']);

        // Point exchange endpoints (authenticated user exchanges their own points)
        Route::post('/points/exchange/preview', [ExchangeController::class, 'preview']);
        Route::post('/points/exchange', [ExchangeController::class, 'exchange']);

        // Third-party endpoints (access other customer's data with scoped abilities)
        // Provider-scoped customer point operations
        Route::prefix('providers/{provider}/customers/{customer}')->group(function (): void {
            Route::get('/points', [CustomerPointController::class, 'show'])
                ->middleware('ability:points:read');

            Route::get('/transactions', [CustomerPointController::class, 'transactions'])
                ->middleware('ability:transactions:read');

            Route::post('/points/award', [CustomerPointController::class, 'award'])
                ->middleware('ability:points:award');

            Route::post('/points/deduct', [CustomerPointController::class, 'deduct'])
                ->middleware('ability:points:deduct');
        });
    });
});
