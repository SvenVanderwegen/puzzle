<?php

declare(strict_types=1);

namespace App\Domain\Streaks;

use App\Models\Streak;

/**
 * Streak reads + GDPR paths. Streak progression (UTC day math, freezes) is WS-07;
 * until then freeze_available derives conservatively from freeze_available_at and
 * safe_until stays null.
 */
final class StreakStore
{
    /**
     * Streak summary per contracts/openapi.yaml #/components/schemas/Streak.
     *
     * @return array{current: int, best: int, last_daily_date: string|null, freeze_available: bool, safe_until: string|null}
     */
    public function summaryFor(string $userId): array
    {
        /** @var Streak|null $row */
        $row = Streak::query()->find($userId);

        return [
            'current' => $row->current_len ?? 0,
            'best' => $row->best_len ?? 0,
            'last_daily_date' => $row?->last_daily_date?->format('Y-m-d'),
            'freeze_available' => $row === null
                || $row->freeze_available_at === null
                || $row->freeze_available_at->lessThanOrEqualTo(now()),
            'safe_until' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function exportFor(string $userId): ?array
    {
        /** @var Streak|null $row */
        $row = Streak::query()->find($userId);

        if ($row === null) {
            return null;
        }

        return [
            'current_len' => $row->current_len,
            'best_len' => $row->best_len,
            'last_daily_date' => $row->last_daily_date?->format('Y-m-d'),
            'freeze_available_at' => $row->freeze_available_at?->format('Y-m-d'),
            'frozen_dates' => $row->frozen_dates,
            'updated_at' => $row->updated_at?->toJSON(),
        ];
    }

    /**
     * GDPR erasure: the streaks row is deleted outright (brief: aggregates keep
     * nothing per-user here).
     */
    public function eraseUser(string $userId): void
    {
        Streak::query()->where('user_id', $userId)->delete();
    }
}
