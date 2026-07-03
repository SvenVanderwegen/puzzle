<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\PiiScrubber;
use App\Http\Controllers\Controller;
use App\Models\FrontendError;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/v1/errors — first-party error beacon (ADR-0008,
 * contracts/openapi.yaml recordFrontendError). Anonymous, sampled
 * client-side, 10/min per session (AppServiceProvider `frontend-errors`
 * limiter; session id when the SPA cookie is present, IP fallback otherwise).
 *
 * The contract declares only 202 and 429 for this operation — a beacon is
 * fire-and-forget, so nothing here returns 422: a payload without a usable
 * message is dropped without a row, and oversized fields are truncated to the
 * contract caps. Message, stack and route are PII-scrubbed (emails, bearer
 * tokens, token query params) BEFORE storage; scrub first, then truncate, so
 * a placeholder can never push a field past its cap (docs/gdpr.md).
 */
final class FrontendErrorController extends Controller
{
    private const int MESSAGE_MAX = 2000;

    private const int STACK_MAX = 8000;

    private const int ROUTE_MAX = 200;

    public function store(Request $request, PiiScrubber $scrubber): Response
    {
        $message = $request->json('message');

        if (is_string($message) && trim($message) !== '') {
            $stack = $request->json('stack');
            $route = $request->json('route');

            FrontendError::query()->create([
                'message' => $this->clean($message, self::MESSAGE_MAX, $scrubber),
                'stack' => is_string($stack) ? $this->clean($stack, self::STACK_MAX, $scrubber) : null,
                'route' => is_string($route) ? $this->clean($route, self::ROUTE_MAX, $scrubber) : null,
                'created_at' => now(),
            ]);
        }

        return response()->noContent(202);
    }

    private function clean(string $value, int $max, PiiScrubber $scrubber): string
    {
        return mb_substr($scrubber->scrub($value), 0, $max);
    }
}
