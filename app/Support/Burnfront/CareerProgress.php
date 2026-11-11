<?php

namespace App\Support\Burnfront;

/**
 * A small set of milestone badges, read straight off data DailyScore and
 * EndlessScore already record — no new table, and nothing here gates any
 * mode. The player's rank is not here: it follows from their points (see
 * Progression).
 */
final class CareerProgress
{
    /**
     * @param  array{totalSolved: int, bestStreak: int, hasCleanDaily: bool, hasColdCase: bool}  $facts
     * @return list<array{key: string, label: string, description: string, earned: bool}>
     */
    public static function badges(array $facts): array
    {
        return [
            [
                'key' => 'first_incident',
                'label' => 'On The Case',
                'description' => 'Close your first incident.',
                'earned' => $facts['totalSolved'] >= 1,
            ],
            [
                'key' => 'clean_reconstruction',
                'label' => 'Clean Reconstruction',
                'description' => 'Solve a daily incident with no hints borrowed.',
                'earned' => $facts['hasCleanDaily'],
            ],
            [
                'key' => 'week_streak',
                'label' => 'Week On The Line',
                'description' => 'Reach a 7-day daily streak.',
                'earned' => $facts['bestStreak'] >= 7,
            ],
            [
                'key' => 'month_streak',
                'label' => 'Standing Watch',
                'description' => 'Reach a 30-day daily streak.',
                'earned' => $facts['bestStreak'] >= 30,
            ],
            [
                'key' => 'century',
                'label' => 'Century Club',
                'description' => 'Close 100 incidents in total.',
                'earned' => $facts['totalSolved'] >= 100,
            ],
            [
                'key' => 'cold_case',
                'label' => 'Cold Case Closed',
                'description' => 'Close a Cold Case incident.',
                'earned' => $facts['hasColdCase'],
            ],
        ];
    }
}
