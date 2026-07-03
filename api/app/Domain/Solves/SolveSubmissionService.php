<?php

declare(strict_types=1);

namespace App\Domain\Solves;

use App\Domain\Ratings\Events\RatableSolveRecorded;
use App\Domain\Ratings\RatingService;
use App\Domain\Streaks\StreakService;
use App\Models\DailyPuzzle;
use App\Models\DailyStat;
use App\Models\Puzzle;
use App\Models\Solve;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /solves write path (contracts/openapi.yaml submitSolve).
 *
 * - Idempotent via client_solve_id (= Idempotency-Key): duplicates replay the
 *   stored response_snapshot with 200.
 * - Validity is authoritative server-side (BurnValidator; vectors are law).
 * - official_ms = min(client_ms, received_at - fetched_at); clock lies in
 *   either direction mark the solve valid-but-suspect (percentile-ineligible),
 *   never rejected.
 * - Replay integrity per ADR-0012: sha256 over the UNCOMPRESSED replay JSON,
 *   verified after gunzip; a mismatch rejects the submission (422).
 */
final class SolveSubmissionService
{
    private const PERCEPTUAL_FLOOR_MS_PER_BREAK = 250;

    public function __construct(
        private readonly BurnValidator $validator,
        private readonly StreakService $streaks,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated submitSolve payload
     * @return array{status: int, body: array<string, mixed>}
     */
    public function submit(User $user, string $clientSolveId, array $input, ?string $ip, ?string $userAgent): array
    {
        $replay = $this->replayForUser($user->id, $clientSolveId);

        if ($replay !== null) {
            return $replay;
        }

        $receivedAt = CarbonImmutable::now('UTC');
        $today = $receivedAt->format('Y-m-d');

        /** @var string $mode */
        $mode = $input['mode'];
        /** @var string $shaded */
        $shaded = $input['shaded'];
        /** @var int $clientMs */
        $clientMs = $input['client_ms'];
        /** @var array{s1: int, s2: int, s3: int} $hints */
        $hints = $input['hints'];

        [$board, $puzzle, $daily] = $this->resolveBoard($mode, $input, $today);

        if (strlen($shaded) !== $board->cellCount()) {
            throw ValidationException::withMessages([
                'shaded' => 'shaded must cover exactly rows x cols cells.',
            ]);
        }

        [$replayRaw, $replayDurationMs] = $this->verifyReplay($input);

        $verdict = $this->validator->verdict($board, $shaded);

        if ($verdict->valid && $puzzle !== null && hash('sha256', $shaded) !== $puzzle->solution_sha256) {
            // Content corruption tripwire: a BFS-valid shading of a certified
            // unique board MUST hash to the published solution. Never surfaced
            // to the player; ops alert only.
            Log::critical('burnfront.content: valid shading does not match solution_sha256', [
                'puzzle_id' => $puzzle->id,
                'client_solve_id' => $clientSolveId,
            ]);
        }

        if ($verdict->valid && $mode === 'daily' && $puzzle !== null && $this->alreadySolved($user->id, $puzzle->id)) {
            throw ValidationException::withMessages([
                'puzzle_id' => 'This incident is already contained by this account.',
            ]);
        }

        [$officialMs, $suspect] = $verdict->valid
            ? $this->clampClock($mode, $user->id, $puzzle?->id, $board, $clientMs, $receivedAt, $replayDurationMs)
            : [null, false];

        $ratable = $verdict->valid && ! $suspect && $hints['s3'] === 0;

        try {
            $body = DB::transaction(function () use (
                $user, $clientSolveId, $input, $ip, $userAgent, $receivedAt, $today, $mode,
                $shaded, $clientMs, $hints, $board, $puzzle, $daily, $replayRaw, $verdict,
                $officialMs, $suspect, $ratable
            ): array {
                $solve = $this->persistSolve(
                    $user, $clientSolveId, $input, $ip, $userAgent, $receivedAt, $mode,
                    $shaded, $clientMs, $hints, $board, $puzzle, $replayRaw, $verdict, $officialMs, $suspect,
                );

                $dailyBody = null;

                if ($verdict->valid && $mode === 'daily' && $daily !== null) {
                    $dailyBody = $this->updateDailyAggregates($daily, $officialMs, $suspect);

                    if ($daily->date === $today) {
                        $this->streaks->creditDailySolve($user->id, $today);
                    }
                }

                $body = [
                    'solve_id' => (string) $solve->id,
                    'valid' => $verdict->valid,
                    'reason' => $verdict->reason->value,
                    'official_ms' => $officialMs,
                    'suspect' => $suspect,
                    'streak' => $this->streaks->summaryFor($user->id),
                    'rating_pending' => $ratable,
                ];

                if ($dailyBody !== null) {
                    $body['daily'] = $dailyBody;
                }

                $solve->response_snapshot = $body;
                $solve->save();

                return ['id' => $solve->id, 'body' => $body];
            });
        } catch (QueryException $e) {
            return $this->mapUniqueViolation($e, $user->id, $clientSolveId);
        }

        if ($ratable) {
            RatableSolveRecorded::dispatch($body['id']);
        }

        return ['status' => 201, 'body' => $body['body']];
    }

    /**
     * @return array{status: int, body: array<string, mixed>}|null
     */
    private function replayForUser(string $userId, string $clientSolveId): ?array
    {
        /** @var Solve|null $existing */
        $existing = Solve::query()
            ->where('user_id', $userId)
            ->where('client_solve_id', $clientSolveId)
            // Failed-daily bookkeeping rows (WS-08) are not submissions and
            // must never replay as one. Their reserved-UUIDv8 keys cannot
            // pass the v7-only header validation anyway; this is the second
            // fence.
            ->where(function ($query): void {
                $query->whereNull('reject_reason')
                    ->orWhere('reject_reason', '<>', RatingService::FAILED_DAILY_REASON);
            })
            ->first();

        if ($existing === null) {
            return null;
        }

        $body = $existing->response_snapshot ?? [
            'solve_id' => (string) $existing->id,
            'valid' => $existing->valid,
            'suspect' => $existing->suspect,
        ];

        return ['status' => 200, 'body' => $body];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{Board, Puzzle|null, DailyPuzzle|null}
     */
    private function resolveBoard(string $mode, array $input, string $today): array
    {
        if ($mode === 'endless') {
            /** @var array<array-key, mixed> $spec */
            $spec = $input['endless_spec'];

            try {
                return [Board::fromArray($spec), null, null];
            } catch (InvalidBoardSpec $e) {
                throw ValidationException::withMessages(['endless_spec' => $e->getMessage()]);
            }
        }

        /** @var string $puzzleId */
        $puzzleId = $input['puzzle_id'];
        /** @var Puzzle|null $puzzle */
        $puzzle = Puzzle::query()->find($puzzleId);

        if ($puzzle === null) {
            throw ValidationException::withMessages(['puzzle_id' => 'Unknown puzzle.']);
        }

        $daily = null;

        if ($mode === 'daily') {
            /** @var DailyPuzzle|null $daily */
            $daily = DailyPuzzle::query()->where('puzzle_id', $puzzle->id)->first();

            if ($daily === null || $daily->date > $today) {
                // Future dailies are unpublished; do not leak their existence.
                throw ValidationException::withMessages(['puzzle_id' => 'Unknown puzzle.']);
            }
        }

        try {
            $board = Board::fromArray($puzzle->spec);
        } catch (InvalidBoardSpec $e) {
            Log::critical('burnfront.content: stored puzzle spec is not a valid board', [
                'puzzle_id' => $puzzle->id,
            ]);

            throw ValidationException::withMessages(['puzzle_id' => 'Unknown puzzle.']);
        }

        return [$board, $puzzle, $daily];
    }

    /**
     * Replay integrity (ADR-0012): gunzip, then sha256 over the UNCOMPRESSED
     * JSON bytes; any mismatch rejects. Returns the raw gzip bytes for storage
     * and the replay duration (max t_ms) for the clock plausibility check.
     *
     * @param  array<string, mixed>  $input
     * @return array{string|null, int|null}
     */
    private function verifyReplay(array $input): array
    {
        if (! isset($input['replay']) || ! is_string($input['replay'])) {
            return [null, null];
        }

        $raw = base64_decode($input['replay'], true);

        if ($raw === false) {
            throw ValidationException::withMessages(['replay' => 'replay must be base64-encoded gzip.']);
        }

        $json = @gzdecode($raw);

        if ($json === false) {
            throw ValidationException::withMessages(['replay' => 'replay does not gunzip.']);
        }

        if (isset($input['replay_sha256']) && is_string($input['replay_sha256'])
            && ! hash_equals(hash('sha256', $json), $input['replay_sha256'])) {
            throw ValidationException::withMessages([
                'replay_sha256' => 'replay_sha256 does not match the uncompressed replay bytes (ADR-0012).',
            ]);
        }

        $events = json_decode($json, true);

        if (! is_array($events) || ! array_is_list($events)) {
            throw ValidationException::withMessages(['replay' => 'replay must be a JSON event list.']);
        }

        $duration = 0;

        foreach ($events as $event) {
            if (! is_array($event) || ! array_is_list($event) || count($event) !== 3 || ! is_int($event[0]) || $event[0] < 0) {
                throw ValidationException::withMessages(['replay' => 'replay events must be [t_ms, cellIndex, mark] triples.']);
            }

            $duration = max($duration, $event[0]);
        }

        return [$raw, $duration];
    }

    private function alreadySolved(string $userId, string $puzzleId): bool
    {
        return Solve::query()
            ->where('user_id', $userId)
            ->where('puzzle_id', $puzzleId)
            ->where('mode', 'daily')
            ->where('valid', true)
            ->exists();
    }

    /**
     * official_ms = min(client_ms, received_at - fetched_at), with suspect
     * flags for clock lies: overstated client_ms (beyond the server window),
     * a missing daily fetch anchor, sub-perceptual speed (n_breaks x 250 ms),
     * or a claim faster than the submitted replay's own duration.
     *
     * @return array{int, bool}
     */
    private function clampClock(
        string $mode,
        string $userId,
        ?string $puzzleId,
        Board $board,
        int $clientMs,
        CarbonImmutable $receivedAt,
        ?int $replayDurationMs,
    ): array {
        $suspect = false;
        $windowMs = null;

        if ($puzzleId !== null) {
            /** @var string|null $fetchedAt */
            $fetchedAt = DB::table('puzzle_fetches')
                ->where('user_id', $userId)
                ->where('puzzle_id', $puzzleId)
                ->value('fetched_at');

            if ($fetchedAt !== null) {
                $windowMs = max(0, $receivedAt->getTimestampMs() - CarbonImmutable::parse($fetchedAt)->getTimestampMs());
            } elseif ($mode === 'daily') {
                // The daily flow always stamps /daily/{date}/start first; a
                // solve with no anchor has no verifiable window.
                $suspect = true;
            }
        }

        if ($windowMs !== null && $clientMs > $windowMs) {
            $suspect = true;
        }

        $officialMs = $windowMs === null ? $clientMs : min($clientMs, $windowMs);

        if ($officialMs < $board->breaks * self::PERCEPTUAL_FLOOR_MS_PER_BREAK) {
            $suspect = true;
        }

        if ($replayDurationMs !== null && $officialMs < $replayDurationMs) {
            $suspect = true;
        }

        return [$officialMs, $suspect];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{s1: int, s2: int, s3: int}  $hints
     */
    private function persistSolve(
        User $user,
        string $clientSolveId,
        array $input,
        ?string $ip,
        ?string $userAgent,
        CarbonImmutable $receivedAt,
        string $mode,
        string $shaded,
        int $clientMs,
        array $hints,
        Board $board,
        ?Puzzle $puzzle,
        ?string $replayRaw,
        BurnVerdict $verdict,
        ?int $officialMs,
        bool $suspect,
    ): Solve {
        /** @var string $appKey */
        $appKey = config('app.key');

        $endlessSpec = null;

        if ($mode === 'endless') {
            /** @var int $deductionSteps */
            $deductionSteps = $input['deduction_steps'];
            $endlessSpec = $board->toArray() + ['deduction_steps' => $deductionSteps];
        }

        $solve = new Solve([
            'user_id' => $user->id,
            'puzzle_id' => $puzzle?->id,
            'mode' => $mode,
            'client_solve_id' => $clientSolveId,
            'shaded_bits' => $shaded,
            'client_ms' => $clientMs,
            'official_ms' => $officialMs,
            'started_at' => is_string($input['started_at'] ?? null) ? CarbonImmutable::parse($input['started_at']) : null,
            'received_at' => $receivedAt,
            'valid' => $verdict->valid,
            'reject_reason' => $verdict->valid ? null : $verdict->reason->value,
            'suspect' => $suspect,
            'imported' => false,
            'hints_s1' => $hints['s1'],
            'hints_s2' => $hints['s2'],
            'hints_s3' => $hints['s3'],
            'undo_count' => $input['undo_count'],
            'replay' => $replayRaw === null ? null : '\x'.bin2hex($replayRaw),
            'replay_sha256' => is_string($input['replay_sha256'] ?? null) ? $input['replay_sha256'] : null,
            'ip_hash' => $ip === null ? null : hash_hmac('sha256', $ip, $appKey),
            'ua_hash' => $userAgent === null ? null : hash_hmac('sha256', $userAgent, $appKey),
            'endless_spec' => $endlessSpec,
        ]);

        $solve->save();

        return $solve;
    }

    /**
     * Transactional daily aggregates (daily_stats) + the player's rank and
     * percentile. Suspect solves are percentile-ineligible: aggregates stay
     * untouched and rank/percentile are null.
     *
     * @return array{rank: int|null, percentile: int|null, solved_count: int}
     */
    private function updateDailyAggregates(DailyPuzzle $daily, ?int $officialMs, bool $suspect): array
    {
        if ($suspect) {
            /** @var DailyStat|null $stat */
            $stat = DailyStat::query()->find($daily->date);

            return ['rank' => null, 'percentile' => null, 'solved_count' => $stat->solved_count ?? 0];
        }

        DailyStat::query()->insertOrIgnore([
            ['date' => $daily->date, 'solved_count' => 0, 'started_count' => 0],
        ]);

        /** @var DailyStat $stat */
        $stat = DailyStat::query()->lockForUpdate()->find($daily->date);

        $eligible = DB::table('solves')
            ->where('puzzle_id', $daily->puzzle_id)
            ->where('mode', 'daily')
            ->where('valid', true)
            ->where('suspect', false)
            ->where('imported', false);

        $p50 = $eligible->clone()
            ->selectRaw('percentile_cont(0.5) within group (order by official_ms) as p50')
            ->value('p50');

        $stat->solved_count = $stat->solved_count + 1;
        $stat->p50_ms = is_numeric($p50) ? (int) round((float) $p50) : null;
        $stat->updated_at = Carbon::now('UTC');
        $stat->save();

        $rank = 1 + $eligible->clone()->where('official_ms', '<', $officialMs ?? 0)->count();
        $percentile = (int) floor(100 * ($stat->solved_count - $rank) / max(1, $stat->solved_count));

        return ['rank' => $rank, 'percentile' => max(0, $percentile), 'solved_count' => $stat->solved_count];
    }

    /**
     * Concurrency losers land here: a duplicate Idempotency-Key replays the
     * stored response (200); a second valid daily solve trips the partial
     * unique index and is rejected cleanly (422).
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function mapUniqueViolation(QueryException $e, string $userId, string $clientSolveId): array
    {
        if (str_contains($e->getMessage(), 'solves_user_client_unique')) {
            $replay = $this->replayForUser($userId, $clientSolveId);

            if ($replay !== null) {
                return $replay;
            }
        }

        if (str_contains($e->getMessage(), 'solves_one_valid_daily')) {
            throw ValidationException::withMessages([
                'puzzle_id' => 'This incident is already contained by this account.',
            ]);
        }

        throw $e;
    }
}
