<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DailyController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MagicLinkController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\RatingController;
use App\Http\Controllers\Api\V1\SolveController;
use App\Http\Controllers\Api\V1\StreakController;
use Illuminate\Support\Facades\Route;

// Registered under the api/v1 prefix (bootstrap/app.php); paths mirror contracts/openapi.yaml.

Route::post('/auth/magic-link', [MagicLinkController::class, 'request'])
    ->middleware('throttle:magic-link-request');

Route::post('/auth/magic-link/consume', [MagicLinkController::class, 'consume'])
    ->middleware('throttle:magic-link-consume');

Route::get('/health', HealthController::class);

Route::get('/daily/{date}', [DailyController::class, 'show'])
    ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [MagicLinkController::class, 'logout']);

    Route::get('/me', [MeController::class, 'show']);
    Route::patch('/me', [MeController::class, 'update']);
    Route::delete('/me', [MeController::class, 'destroy']);

    Route::get('/me/export', [ExportController::class, 'request'])
        ->middleware('throttle:me-export');

    Route::post('/daily/{date}/start', [DailyController::class, 'start'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');

    Route::post('/solves', [SolveController::class, 'store'])
        ->middleware('throttle:solves');

    Route::get('/me/solves', [SolveController::class, 'index']);
    Route::get('/me/streak', [StreakController::class, 'show']);
    Route::get('/me/rating', [RatingController::class, 'show']);
});
