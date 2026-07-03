<?php

declare(strict_types=1);

namespace App\Domain\Solves;

use App\Models\DailyPuzzle;
use App\Models\Solve;
use DateTimeInterface;

/**
 * Solves table reads + GDPR paths: history listing (WS-07), disowning,
 * retention purge, export. The write path is SolveSubmissionService.
 */
final class SolveStore
{
    /**
     * Newest-first solve history for GET /me/solves (cursor = the id of the
     * last item of the previous page, opaque to clients).
     *
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function listFor(string $userId, ?int $beforeId, int $limit): array
    {
        $query = Solve::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit($limit + 1);

        if ($beforeId !== null) {
            $query->where('id', '<', $beforeId);
        }

        /** @var list<Solve> $rows */
        $rows = $query->get()->all();

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $puzzleIds = array_values(array_filter(array_map(
            fn (Solve $solve): ?string => $solve->mode === 'daily' ? $solve->puzzle_id : null,
            $rows,
        )));

        /** @var array<string, DailyPuzzle> $dailies */
        $dailies = DailyPuzzle::query()
            ->whereIn('puzzle_id', $puzzleIds)
            ->get()
            ->keyBy('puzzle_id')
            ->all();

        $items = array_map(function (Solve $solve) use ($dailies): array {
            $daily = $solve->puzzle_id !== null && $solve->mode === 'daily'
                ? ($dailies[$solve->puzzle_id] ?? null)
                : null;

            return [
                'solve_id' => (string) $solve->id,
                'mode' => $solve->mode,
                'puzzle_id' => $solve->puzzle_id,
                'date' => $daily?->date,
                'incident_number' => $daily?->incident_number,
                'valid' => $solve->valid,
                'official_ms' => $solve->official_ms,
                'clean' => $solve->hints_s1 === 0 && $solve->hints_s2 === 0 && $solve->hints_s3 === 0,
                'received_at' => $solve->received_at->toJSON(),
            ];
        }, $rows);

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return [
            'items' => $items,
            'next_cursor' => $hasMore && $last !== null ? (string) $last->id : null,
        ];
    }

    /**
     * Anonymization: solves survive with user_id NULL (aggregates stay truthful).
     */
    public function disownAllFor(string $userId): int
    {
        return Solve::query()->where('user_id', $userId)->update(['user_id' => null]);
    }

    /**
     * 90-day retention (docs/gdpr.md): null replay + ip_hash + ua_hash on rows
     * received before the cutoff. The solve rows themselves are kept.
     */
    public function purgeExpiredArtifacts(DateTimeInterface $cutoff): int
    {
        return Solve::query()
            ->where('received_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->whereNotNull('replay')
                    ->orWhereNotNull('ip_hash')
                    ->orWhereNotNull('ua_hash');
            })
            ->update(['replay' => null, 'ip_hash' => null, 'ua_hash' => null]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportFor(string $userId): array
    {
        return Solve::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (Solve $solve): array => [
                'mode' => $solve->mode,
                'puzzle_id' => $solve->puzzle_id,
                'client_solve_id' => $solve->client_solve_id,
                'shaded_bits' => self::binary($solve->shaded_bits),
                'client_ms' => $solve->client_ms,
                'official_ms' => $solve->official_ms,
                'started_at' => $solve->started_at?->toJSON(),
                'received_at' => $solve->received_at->toJSON(),
                'valid' => $solve->valid,
                'reject_reason' => $solve->reject_reason,
                'suspect' => $solve->suspect,
                'imported' => $solve->imported,
                'hints' => [
                    's1' => $solve->hints_s1,
                    's2' => $solve->hints_s2,
                    's3' => $solve->hints_s3,
                ],
                'undo_count' => $solve->undo_count,
                'replay_base64' => self::binaryBase64($solve->replay),
                'replay_sha256' => $solve->replay_sha256,
                'endless_spec' => $solve->endless_spec,
            ])
            ->values()
            ->all();
    }

    /**
     * bytea columns surface as stream resources through PDO pgsql.
     */
    private static function binary(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? $value : null;
    }

    private static function binaryBase64(mixed $value): ?string
    {
        $raw = self::binary($value);

        return $raw === null ? null : base64_encode($raw);
    }
}
