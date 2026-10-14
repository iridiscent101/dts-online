<?php

use App\Http\Controllers\ClientDocumentSubmissionController;
use App\Http\Controllers\PublicDocumentTrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', PublicDocumentTrackingController::class)
    ->middleware('throttle:30,1')
    ->name('tracking.index');

Route::get('/submit', [ClientDocumentSubmissionController::class, 'create'])
    ->name('submission.create');
Route::post('/submit', [ClientDocumentSubmissionController::class, 'store'])
    // Schools and the SDO counter share one connection for many clients; bots are caught by the honeypot instead.
    ->middleware('throttle:20,1')
    ->name('submission.store');
