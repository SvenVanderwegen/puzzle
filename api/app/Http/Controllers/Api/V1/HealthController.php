<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyPuzzle;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * GET /health — liveness + content freshness (contracts/openapi.yaml).
 * tomorrow_published feeds the WS-18 T-2h freshness alert.
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $tomorrow = CarbonImmutable::now('UTC')->addDay()->format('Y-m-d');

        return new JsonResponse([
            'ok' => true,
            'tomorrow_published' => DailyPuzzle::query()->whereKey($tomorrow)->exists(),
        ]);
    }
}
