<?php

declare(strict_types=1);

use App\Http\Controllers\ExportDownloadController;
use Illuminate\Support\Facades\Route;

// GDPR export download: signed URL (24 h expiry) + live session required, single
// download. Outside the /api/v1 contract surface — the link is delivered by email.
Route::get('/exports/{userId}/{file}', ExportDownloadController::class)
    ->middleware('signed')
    ->where(['userId' => '[0-9A-Za-z]+', 'file' => '[0-9A-Za-z._-]+'])
    ->name('exports.download');
