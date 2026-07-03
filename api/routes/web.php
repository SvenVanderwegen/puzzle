<?php

declare(strict_types=1);

use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

// The public marketing surface (WS-15; ADR-0009: Blade owns these). `/`
// serves the landing logged-out and redirects live sessions to the SPA hub.
Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::get('/about', [LandingController::class, 'about'])->name('landing.about');
Route::get('/rules', [LandingController::class, 'rules'])->name('landing.rules');

// The one deferred hydration module (committed at resources/landing/hero.js;
// resources/ is not web-served, so Laravel hands it out with immutable caching).
Route::get('/landing/hero.js', [LandingController::class, 'heroModule'])->name('landing.hero');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// GDPR export download: signed URL (24 h expiry) + live session required, single
// download. Outside the /api/v1 contract surface — the link is delivered by email.
Route::get('/exports/{userId}/{file}', ExportDownloadController::class)
    ->middleware('signed')
    ->where(['userId' => '[0-9A-Za-z]+', 'file' => '[0-9A-Za-z._-]+'])
    ->name('exports.download');
