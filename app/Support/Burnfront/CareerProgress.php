<?php

namespace App\Support\Burnfront;

/**
 * The player-facing career ladder docs/concept.md defers as a future,
 * optional "campaign hook": a rank title tied to total incidents closed
 * (daily + every endless tier, including custom-free tiers like Cold
 * Case), plus a small set of milestone badges. Both are read straight off
 * data DailyScore/EndlessScore already record — no new table, and nothing
 * here gates any tier or mode behind a rank the player hasn't reached yet
 * (see BurnfrontController::computeCareer()), same as concept.md asks for.
 */
final class CareerProgress
{
    /**
     * Ascending by threshold. The current rank is the last entry whose
     * threshold the player has met or passed; titles are distinct from the
     * difficulty tier names (Lookout/Crew/Hotshot/...) on purpose, so a
     * player's career rank is never confused with the grid size they're
     * currently playing.
     *
     * @var list<array{threshold: int, title: string}>
     */
    private const RANKS = [
        ['threshold' => 0, 'title' => 'Trainee Analyst'],
        ['threshold' => 5, 'title' => 'Field Analyst'],
        ['threshold' => 20, 'title' => 'Senior Analyst'],
        ['threshold' => 50, 'title' => 'Lead Investigator'],
        ['threshold' => 100, 'title' => 'Chief Investigator'],
        ['threshold' => 250, 'title' => 'Bureau Chief'],
    ];

    /**
     * @return array{title: string, totalSolved: int, currentThreshold: int, nextTitle: string|null, nextThreshold: int|null}
     */
    public static function rank(int $totalSolved): array
    {
        $current = self::RANKS[0];
        $next = null;
        foreach (self::RANKS as $candidate) {
            if ($candidate['threshold'] <= $totalSolved) {
                $current = $candidate;

                continue;
            }
            $next = $candidate;
            break;
        }

        return [
            'title' => $current['title'],
            'totalSolved' => $totalSolved,
            // Lets the UI draw a progress bar toward nextTitle without
            // duplicating the RANKS thresholds client-side.
            'currentThreshold' => $current['threshold'],
            'nextTitle' => $next['title'] ?? null,
            'nextThreshold' => $next['threshold'] ?? null,
        ];
    }

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
