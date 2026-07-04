<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BurnfrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BurnfrontController::class, 'start'])->name('burnfront.start');
Route::get('/endless', [BurnfrontController::class, 'endlessSetup'])->name('burnfront.endless');
Route::get('/endless/play', [BurnfrontController::class, 'endlessPlay'])->name('burnfront.endless.play');
Route::get('/how-to', [BurnfrontController::class, 'howTo'])->name('burnfront.how-to');
Route::get('/puzzle', [BurnfrontController::class, 'puzzle'])->name('burnfront.puzzle');
Route::get('/hint', [BurnfrontController::class, 'hint'])->name('burnfront.hint');
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

    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::get('/account/settings', [AccountController::class, 'edit'])->name('account.settings');
    Route::patch('/account/settings', [AccountController::class, 'update'])->name('account.settings.update');
    Route::put('/account/settings/password', [AccountController::class, 'updatePassword'])->name('account.settings.password');
});
