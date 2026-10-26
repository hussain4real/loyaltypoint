<?php

declare(strict_types=1);

use App\Http\Controllers\BackOffice\DashboardController;
use App\Http\Controllers\BackOffice\ElevationController;
use App\Http\Controllers\BackOffice\SessionController;
use App\Http\Middleware\BackOfficeHeaders;
use App\Http\Middleware\RequireStaffMfa;
use Illuminate\Support\Facades\Route;

Route::prefix('backoffice')->name('backoffice.')->middleware(BackOfficeHeaders::class)->group(function (): void {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/mfa', [SessionController::class, 'challenge'])->name('mfa');
    Route::post('/mfa/enrollment', [SessionController::class, 'enroll'])->middleware('throttle:5,1')->name('mfa.enroll');
    Route::post('/mfa', [SessionController::class, 'verify'])->middleware('throttle:10,1')->name('mfa.verify');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::middleware(RequireStaffMfa::class)->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/cases', [DashboardController::class, 'cases'])->name('cases');
        Route::post('/cases/{case}', [DashboardController::class, 'transition'])->name('cases.transition');
        Route::get('/audit', [DashboardController::class, 'audits'])->name('audit');
        Route::get('/audit/export', [DashboardController::class, 'export'])->name('audit.export');
        Route::post('/elevations', [ElevationController::class, 'store'])->name('elevations.store');
        Route::delete('/elevations/{elevation}', [ElevationController::class, 'destroy'])->name('elevations.destroy');
    });
});
