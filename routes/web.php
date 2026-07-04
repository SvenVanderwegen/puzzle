<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BurnfrontController;
use App\Http\Controllers\CampaignController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BurnfrontController::class, 'start'])->name('burnfront.start');
Route::get('/endless', [BurnfrontController::class, 'endlessSetup'])->name('burnfront.endless');
Route::get('/endless/play', [BurnfrontController::class, 'endlessPlay'])->name('burnfront.endless.play');
Route::get('/how-to', [BurnfrontController::class, 'howTo'])->name('burnfront.how-to');
Route::get('/puzzle', [BurnfrontController::class, 'puzzle'])->name('burnfront.puzzle');
Route::get('/hint', [BurnfrontController::class, 'hint'])->name('burnfront.hint');
Route::get('/solve', [BurnfrontController::class, 'solve'])->name('burnfront.solve');
Route::get('/daily/leaderboard', [BurnfrontController::class, 'dailyLeaderboard'])->name('burnfront.daily.leaderboard');

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('/daily', [BurnfrontController::class, 'daily'])->name('burnfront.daily');
    Route::get('/daily/play', [BurnfrontController::class, 'dailyPlay'])->name('burnfront.daily.play');
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::post('/daily/score', [BurnfrontController::class, 'submitDailyScore'])->name('burnfront.daily.score');
    Route::get('/daily/history', [BurnfrontController::class, 'dailyHistory'])->name('burnfront.daily.history');
    Route::get('/daily/history/play', [BurnfrontController::class, 'dailyHistoryPlay'])->name('burnfront.daily.history.play');
    Route::post('/endless/score', [BurnfrontController::class, 'submitEndlessScore'])->name('burnfront.endless.score');
    Route::get('/game/history', [BurnfrontController::class, 'gameHistory'])->name('burnfront.game.history');

    Route::get('/campaign', [CampaignController::class, 'map'])->name('burnfront.campaign');
    Route::get('/campaign/play', [CampaignController::class, 'play'])->name('burnfront.campaign.play');
    Route::get('/campaign/puzzle', [CampaignController::class, 'puzzle'])->name('burnfront.campaign.puzzle');
    Route::post('/campaign/score', [CampaignController::class, 'submitScore'])->name('burnfront.campaign.score');

    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::get('/account/settings', [AccountController::class, 'edit'])->name('account.settings');
    Route::patch('/account/settings', [AccountController::class, 'update'])->name('account.settings.update');
    Route::put('/account/settings/password', [AccountController::class, 'updatePassword'])->name('account.settings.password');
});
