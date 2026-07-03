<?php

use App\Http\Controllers\BurnfrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', [BurnfrontController::class, 'index'])->name('burnfront.index');
Route::get('/puzzle', [BurnfrontController::class, 'puzzle'])->name('burnfront.puzzle');
Route::get('/hint', [BurnfrontController::class, 'hint'])->name('burnfront.hint');
