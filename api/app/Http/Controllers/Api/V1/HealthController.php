<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyPuzzle;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * GET /health — liveness + content freshness (contracts/openapi.yaml).
 * tomorrow_published feeds the WS-18 T-2h freshness alert; the external
 * uptime check polls this route (docs/RUNBOOK.md §6). The probe dependency
 * is the database: a row for tomorrow exists iff a signed calendar covering
 * it was imported and published. Database unreachable = the contract's 503
 * degraded path (Redis/queue health is Nightwatch's beat, not this probe's).
 */
final class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $tomorrow = CarbonImmutable::now('UTC')->addDay()->format('Y-m-d');

        try {
            $tomorrowPublished = DailyPuzzle::query()->whereKey($tomorrow)->exists();
        } catch (Throwable $e) {
            report($e);

            return new JsonResponse([
                'error' => ['code' => 'degraded', 'message' => 'The database is unreachable.'],
            ], 503);
        }

        return new JsonResponse([
            'ok' => true,
            'tomorrow_published' => $tomorrowPublished,
        ]);
    }
}
