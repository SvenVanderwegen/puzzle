<?php

declare(strict_types=1);

use App\Http\Controllers\DailyShareController;
use App\Http\Controllers\ExportDownloadController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StreakAlertUnsubscribeController;
use Illuminate\Support\Facades\Route;

// The public marketing surface (WS-15; ADR-0009: Blade owns these). `/`
// serves the landing logged-out and redirects live sessions to the SPA hub.
Route::get('/', [LandingController::class, 'index'])->name('landing');
Route::get('/about', [LandingController::class, 'about'])->name('landing.about');
Route::get('/rules', [LandingController::class, 'rules'])->name('landing.rules');

// GET /daily/{date} — dated Burn Order unfurl (WS-10). The date-shaped
// constraint keeps `/daily` (today, SPA-served from WS-16/17) and any
// non-date path off this handler; impossible dates 404 inside the controller.
Route::get('/daily/{date}', [DailyShareController::class, 'show'])
    ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}')
    ->name('daily.share');

// The one deferred hydration module (committed at resources/landing/hero.js;
// resources/ is not web-served, so Laravel hands it out with immutable caching).
Route::get('/landing/hero.js', [LandingController::class, 'heroModule'])->name('landing.hero');

Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

// Legal pages (WS-14, product §1): static Blade rendering owner-approved
// copy. The current text is an AGENT DRAFT pending owner + lawyer review —
// sources with owner-field markers live in docs/legal/ (critique #29).
// Route::view keeps these route:cache-safe (no closures).
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/imprint', 'legal.imprint')->name('legal.imprint');

// GDPR export download: signed URL (24 h expiry) + live session required, single
// download. Outside the /api/v1 contract surface — the link is delivered by email.
Route::get('/exports/{userId}/{file}', ExportDownloadController::class)
    ->middleware('signed')
    ->where(['userId' => '[0-9A-Za-z]+', 'file' => '[0-9A-Za-z._-]+'])
    ->name('exports.download');

// Streak-alert unsubscribe (WS-21): target of the mailed link (GET) and of the
// RFC 8058 List-Unsubscribe-Post header (POST). The signature is the whole
// credential — one-click, no login, single-purpose. Outside the /api/v1
// contract surface, same pattern as exports.download. The POST leg is CSRF-
// exempt in bootstrap/app.php (mailbox providers post without a session).
Route::match(['get', 'post'], '/email/streak-alert/unsubscribe/{userId}', StreakAlertUnsubscribeController::class)
    ->middleware('signed')
    ->where('userId', '[0-9A-Za-z]+')
    ->name('email.streak-alert.unsubscribe');
