<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\MagicLinkController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

// Registered under the api/v1 prefix (bootstrap/app.php); paths mirror contracts/openapi.yaml.

Route::post('/auth/magic-link', [MagicLinkController::class, 'request'])
    ->middleware('throttle:magic-link-request');

Route::post('/auth/magic-link/consume', [MagicLinkController::class, 'consume'])
    ->middleware('throttle:magic-link-consume');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/auth/logout', [MagicLinkController::class, 'logout']);

    Route::get('/me', [MeController::class, 'show']);
    Route::patch('/me', [MeController::class, 'update']);
    Route::delete('/me', [MeController::class, 'destroy']);

    Route::get('/me/export', [ExportController::class, 'request'])
        ->middleware('throttle:me-export');
});
