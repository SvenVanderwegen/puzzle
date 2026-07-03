<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

use App\Models\Rating;
use App\Models\RatingEvent;

/**
 * Read/GDPR surface of the rating tables (only Domain\Ratings touches the
 * rating models — arch-tested). The Glicko-2 write path is RatingService;
 * deterministic replay is RatingRecompute.
 */
final class RatingStore
{
    public const DEFAULT_RATING = 1500.0;

    public const DEFAULT_RD = 350.0;

    public const DEFAULT_VOLATILITY = 0.06;

    public const CALIBRATION_GAMES = 10;

    public const SPARKLINE_LENGTH = 30;

    /**
     * Rating summary per contracts/openapi.yaml #/components/schemas/Rating.
     * `calibrating` is true for the first 10 rated solves (RATING.md §5); the
     * sparkline is the last 30 post-solve ratings, oldest first.
     *
     * @return array{rating: float, rd: float, volatility: float, games: int, calibrating: bool, sparkline: list<float>}
     */
    public function summaryFor(string $userId): array
    {
        /** @var Rating|null $row */
        $row = Rating::query()->find($userId);

        $games = $row->games ?? 0;

        return [
            'rating' => $row->rating ?? self::DEFAULT_RATING,
            'rd' => $row->rd ?? self::DEFAULT_RD,
            'volatility' => $row->volatility ?? self::DEFAULT_VOLATILITY,
            'games' => $games,
            'calibrating' => $games < self::CALIBRATION_GAMES,
            'sparkline' => $this->sparklineFor($userId),
        ];
    }

    /**
     * @return list<float>
     */
    public function sparklineFor(string $userId): array
    {
        $recent = RatingEvent::query()
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->limit(self::SPARKLINE_LENGTH)
            ->pluck('user_after')
            ->reverse()
            ->map(fn (mixed $after): float => is_numeric($after) ? (float) $after : 0.0)
            ->all();

        return array_values($recent);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function exportFor(string $userId): ?array
    {
        /** @var Rating|null $row */
        $row = Rating::query()->find($userId);

        if ($row === null) {
            return null;
        }

        return [
            'rating' => $row->rating,
            'rd' => $row->rd,
            'volatility' => $row->volatility,
            'games' => $row->games,
            'updated_at' => $row->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportEventsFor(string $userId): array
    {
        return RatingEvent::query()
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get()
            ->map(fn (RatingEvent $event): array => [
                'solve_id' => $event->solve_id,
                'puzzle_id' => $event->puzzle_id,
                'score' => $event->score,
                'weight' => $event->weight,
                'user_before' => $event->user_before,
                'user_after' => $event->user_after,
                'created_at' => $event->created_at?->toJSON(),
            ])
            ->values()
            ->all();
    }

    /**
     * GDPR erasure: the ratings row is deleted; audit events are kept for
     * deterministic recompute but disowned (user_id NULL per the schema note).
     */
    public function eraseUser(string $userId): void
    {
        Rating::query()->where('user_id', $userId)->delete();
        RatingEvent::query()->where('user_id', $userId)->update(['user_id' => null]);
    }
}
