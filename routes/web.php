<?php

use App\Http\Controllers\ClientDocumentSubmissionController;
use App\Http\Controllers\DocumentReceiptController;
use App\Http\Controllers\PublicDocumentTrackingController;
use App\Http\Controllers\PublicReleaseSignatureController;
use Illuminate\Support\Facades\Route;

Route::get('/', PublicDocumentTrackingController::class)
    ->middleware('throttle:30,1')
    ->name('tracking.index');

// Printable receipt with the tracking number and QR code (Fast Track "Print receipt").
Route::get('/receipt/{tracking}', DocumentReceiptController::class)
    ->middleware('throttle:30,1')
    ->name('tracking.receipt');

// The client signs for a released document that Records released without a signature at the counter.
Route::post('/tracking/{tracking}/signature', PublicReleaseSignatureController::class)
    ->middleware('throttle:10,1')
    ->name('tracking.signature');

Route::get('/submit', [ClientDocumentSubmissionController::class, 'create'])
    ->name('submission.create');
Route::post('/submit', [ClientDocumentSubmissionController::class, 'store'])
    // Schools and the SDO counter share one connection for many clients; bots are caught by the honeypot instead.
    ->middleware('throttle:20,1')
    ->name('submission.store');
