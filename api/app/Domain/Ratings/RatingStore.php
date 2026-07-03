<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

use App\Models\Rating;
use App\Models\RatingEvent;

/**
 * The only writer of ratings / board_ratings / rating_events (arch-tested).
 * Glicko-2 updates themselves are WS-08; WS-06 needs summaries and GDPR paths.
 */
final class RatingStore
{
    public const DEFAULT_RATING = 1500.0;

    public const DEFAULT_RD = 350.0;

    public const DEFAULT_VOLATILITY = 0.06;

    public const CALIBRATION_GAMES = 10;

    /**
     * Rating summary per contracts/openapi.yaml #/components/schemas/Rating.
     *
     * @return array{rating: float, rd: float, volatility: float, games: int, calibrating: bool}
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
        ];
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
