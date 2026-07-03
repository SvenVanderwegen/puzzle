<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ratings\RatingStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/rating (contracts/openapi.yaml #/components/schemas/Rating).
 * Serves stored values with calibration defaults; the Glicko-2 math that
 * populates them is WS-08.
 */
final class RatingController extends Controller
{
    public function show(Request $request, RatingStore $ratings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return new JsonResponse($ratings->summaryFor($user->id));
    }
}
