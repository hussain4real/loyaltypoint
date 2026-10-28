<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V2\ConsumerContactController;
use App\Http\Controllers\Api\V2\ConsumerEnrollmentController;
use App\Http\Controllers\Api\V2\ConsumerSessionController;
use App\Http\Controllers\Api\V2\IdentityCallbackController;
use App\Http\Middleware\RequireConsumerSession;
use Illuminate\Support\Facades\Route;

Route::prefix('v2')->name('v2.consumer.')->group(function (): void {
    Route::post('/auth/registrations', [ConsumerContactController::class, 'store'])->middleware('throttle:10,1')->name('register');
    Route::post('/auth/contact-verifications', [ConsumerContactController::class, 'verify'])->middleware('throttle:20,1')->name('verify-contact');
    Route::post('/auth/contact-verifications/{reference}/resend', [ConsumerContactController::class, 'resend'])->whereUuid('reference')->middleware('throttle:5,1')->name('resend-contact');
    Route::post('/auth/challenges', [ConsumerSessionController::class, 'startLogin'])->middleware('throttle:10,1')->name('login-challenge');
    Route::post('/auth/sessions', [ConsumerSessionController::class, 'store'])->middleware('throttle:20,1')->name('login');
    Route::middleware(['auth:sanctum', RequireConsumerSession::class.':allow_locked'])->group(function (): void {
        Route::get('/auth/session', [ConsumerSessionController::class, 'show'])->name('session');
        Route::post('/auth/session/lock', [ConsumerSessionController::class, 'lock'])->name('lock');
        Route::post('/auth/session/unlock', [ConsumerSessionController::class, 'unlock'])->middleware('throttle:10,1')->name('unlock');
        Route::post('/auth/logout', [ConsumerSessionController::class, 'destroy'])->name('logout');
    });
    Route::middleware(['auth:sanctum', RequireConsumerSession::class])->group(function (): void {
        Route::post('/auth/devices/challenges', [ConsumerSessionController::class, 'beginDeviceEnrollment'])->name('device-challenge');
        Route::get('/auth/devices', [ConsumerSessionController::class, 'devices'])->name('devices');
        Route::post('/auth/devices', [ConsumerSessionController::class, 'enrollDevice'])->name('device-enroll');
        Route::delete('/auth/devices/{reference}', [ConsumerSessionController::class, 'revokeDevice'])->whereUuid('reference')->name('device-revoke');
        Route::post('/auth/step-up/challenges', [ConsumerSessionController::class, 'startStepUp'])->name('step-up-challenge');
        Route::post('/auth/step-up', [ConsumerSessionController::class, 'verifyStepUp'])->name('step-up');
        Route::get('/eligibility', [ConsumerEnrollmentController::class, 'eligibility'])->name('eligibility');
        Route::get('/enrollments/current', [ConsumerEnrollmentController::class, 'current'])->name('enrollment');
        Route::post('/enrollments', [ConsumerEnrollmentController::class, 'store'])->name('enrollment-submit');
        Route::post('/enrollments/{revision}/remediation', [ConsumerEnrollmentController::class, 'remediate'])->whereUuid('revision')->name('enrollment-remediate');
        Route::post('/identity/uploads', [ConsumerEnrollmentController::class, 'upload'])->name('identity-upload');
    });
    Route::post('/identity/callbacks/{source}', IdentityCallbackController::class)->where('source', '[a-z0-9-]{1,64}')->middleware('throttle:60,1')->name('identity-callback');
});
