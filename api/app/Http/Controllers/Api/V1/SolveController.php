<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Solves\SolveStore;
use App\Domain\Solves\SolveSubmissionService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * POST /solves + GET /me/solves (contracts/openapi.yaml). Submission logic
 * lives in SolveSubmissionService; this controller only validates shape.
 */
final class SolveController extends Controller
{
    public function store(Request $request, SolveSubmissionService $submissions): JsonResponse
    {
        $clientSolveId = $request->header('Idempotency-Key');

        if (! is_string($clientSolveId) || ! Str::isUuid($clientSolveId)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'The Idempotency-Key header must be a client-generated UUID.',
            ]);
        }

        /** @var array<string, mixed> $validated */
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:daily,pack,endless'],
            'puzzle_id' => ['required_unless:mode,endless', 'prohibited_if:mode,endless', 'string', 'max:64'],
            'endless_spec' => ['required_if:mode,endless', 'prohibited_unless:mode,endless', 'array'],
            'shaded' => ['required', 'string', 'regex:/^[01]+$/', 'max:144'],
            'client_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'started_at' => ['required', 'string', 'date'],
            'hints' => ['required', 'array:s1,s2,s3'],
            'hints.s1' => ['required', 'integer', 'min:0', 'max:200'],
            'hints.s2' => ['required', 'integer', 'min:0', 'max:200'],
            'hints.s3' => ['required', 'integer', 'min:0', 'max:200'],
            'undo_count' => ['required', 'integer', 'min:0', 'max:100000'],
            'replay' => ['sometimes', 'string', 'max:262144'],
            'replay_sha256' => ['sometimes', 'string', 'regex:/^[0-9a-f]{64}$/'],
            // Endless only: the client-graded deduction-chain length feeding
            // the board rating prior (RATING.md §4). Required there so WS-08
            // can rate the solve.
            'deduction_steps' => ['required_if:mode,endless', 'prohibited_unless:mode,endless', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $result = $submissions->submit(
            $user,
            Str::lower($clientSolveId),
            $validated,
            $request->ip(),
            $request->userAgent(),
        );

        return new JsonResponse($result['body'], $result['status']);
    }

    public function index(Request $request, SolveStore $solves): JsonResponse
    {
        /** @var array{cursor?: string, limit?: int|string} $validated */
        $validated = $request->validate([
            'cursor' => ['sometimes', 'string', 'regex:/^[0-9]{1,19}$/'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $page = $solves->listFor(
            $user->id,
            isset($validated['cursor']) ? (int) $validated['cursor'] : null,
            (int) ($validated['limit'] ?? 25), // Query params arrive as strings.
        );

        return new JsonResponse($page);
    }
}
