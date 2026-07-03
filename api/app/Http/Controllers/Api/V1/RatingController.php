<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ratings\RatingStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /me/rating (contracts/openapi.yaml #/components/schemas/Rating): the
 * live Glicko-2 values with the last-30-solves sparkline and the first-10
 * calibration flag (RATING.md §5). Updates land via the queued WS-08
 * listeners; this endpoint only reads.
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
