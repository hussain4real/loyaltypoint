<?php

use App\Http\Controllers\Api\V1\CustomerPointController;
use App\Http\Controllers\Api\V1\PointController;
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

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    // Customer endpoints (authenticated user's own data)
    Route::get('/points/balance', [PointController::class, 'balance']);
    Route::get('/points/transactions', [PointController::class, 'transactions']);

    // Third-party endpoints (access other customer's data with scoped abilities)
    Route::prefix('customers/{customer}')->group(function (): void {
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
