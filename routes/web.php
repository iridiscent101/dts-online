<?php

use App\Http\Controllers\ClientDocumentSubmissionController;
use App\Http\Controllers\DocumentReceiptController;
use App\Http\Controllers\ForgotPasswordController;
use App\Http\Controllers\KioskController;
use App\Http\Controllers\NotificationEmailVerificationController;
use App\Http\Controllers\PublicDocumentLabelController;
use App\Http\Controllers\PublicDocumentTrackingController;
use App\Http\Middleware\EnsureAccountIsActive;
use Illuminate\Support\Facades\Route;

Route::get('/', PublicDocumentTrackingController::class)
    ->middleware('throttle:30,1')
    ->name('tracking.index');

// Staff forgot password: a one-time reset link goes to the account's confirmed recovery email.
Route::get('/forgot-password', [ForgotPasswordController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('password.forgot');
Route::post('/forgot-password', [ForgotPasswordController::class, 'send'])
    ->middleware('throttle:5,1')
    ->name('password.forgot.send');
Route::get('/reset-password/{token}', [ForgotPasswordController::class, 'showReset'])
    ->middleware('throttle:30,1')
    ->name('password.reset');
Route::post('/reset-password', [ForgotPasswordController::class, 'reset'])
    ->middleware('throttle:10,1')
    ->name('password.update');

Route::get('/notification-email/verify/{user}/{hash}', NotificationEmailVerificationController::class)
    ->middleware(['signed:relative', 'throttle:6,1'])
    ->name('notification-email.verify');

// Printable receipt with the tracking number and QR code (Fast Track "Print receipt").
Route::get('/receipt/{tracking}', DocumentReceiptController::class)
    ->middleware('throttle:30,1')
    ->name('tracking.receipt');

// The 60 x 30 mm tracking label (Fast Track "Print label").
Route::get('/label/{tracking}', PublicDocumentLabelController::class)
    ->middleware('throttle:30,1')
    ->name('tracking.label');

// Signing kiosk outside the Records Unit, for an account with only the "Signing kiosk" permission. It checks for the
// clerk's request every few seconds, so it gets a higher limit than the public pages.
// 'auth.session' signs the kiosk out when its password changes, as it does for staff.
Route::middleware(['auth', 'auth.session', EnsureAccountIsActive::class, 'throttle:120,1'])->group(function (): void {
    Route::get('/kiosk', [KioskController::class, 'show'])->name('kiosk');
    Route::get('/kiosk/current', [KioskController::class, 'current'])->name('kiosk.current');
    Route::post('/kiosk/{signing}/sign', [KioskController::class, 'sign'])->name('kiosk.sign');
});

Route::get('/submit', [ClientDocumentSubmissionController::class, 'create'])
    ->name('submission.create');
Route::post('/submit', [ClientDocumentSubmissionController::class, 'store'])
    // Schools and the SDO counter share one connection for many clients; bots are caught by the honeypot instead.
    ->middleware('throttle:20,1')
    ->name('submission.store');
