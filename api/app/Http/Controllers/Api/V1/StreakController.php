<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Streaks\StreakService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/streak (contracts/openapi.yaml #/components/schemas/Streak).
 */
final class StreakController extends Controller
{
    public function show(Request $request, StreakService $streaks): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse($streaks->summaryFor($user->id));
    }
}
