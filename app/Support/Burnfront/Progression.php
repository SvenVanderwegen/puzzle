<?php

namespace App\Support\Burnfront;

/**
 * One points ladder for the whole game. Every solved fire, in every mode,
 * gives points; points raise the player's level; every few levels the
 * player moves up to a new rank. Levels never run out, so there is always
 * a next step to play for.
 *
 * This class is pure maths over the numbers in config/burnfront.php ('xp').
 * Reading and writing a player's points lives in PlayerProgress. The same
 * rules are handed to the client as rules(), and resources/js/lib/xp.js
 * mirrors standing() and reward() so the result screen can animate the
 * points, and so a guest (no server record) gets the same numbers from
 * their own device. Keep the two in step.
 *
 * Nothing here ever takes points away: there is no penalty for a slow
 * solve, a missed day or a hint, only extra points for doing well.
 */
final class Progression
{
    /**
     * Rank keys, lowest first. The words shown to the player live in the
     * client's locale files (progress.rank.<key>).
     *
     * @var list<string>
     */
    public const RANKS = ['trainee', 'firefighter', 'crewLeader', 'captain', 'chief', 'commander', 'legend'];

    /**
     * @return array{levelBase: int, levelStep: int, levelCap: int, rankLevels: list<int>, ranks: list<string>, fireBase: int, firePerSide: int, fireMin: int, fireMax: int, noHints: int, daily: int, streakPerDay: int, streakMaxDays: int, weekGoal: int}
     */
    public static function rules(): array
    {
        $c = (array) config('burnfront.xp', []);
        $int = fn (string $key, int $default, int $min = 0): int => max($min, (int) ($c[$key] ?? $default));

        $rankLevels = array_values(array_map('intval', (array) ($c['rank_levels'] ?? [1, 5, 9, 13, 17, 21, 25])));
        $rankLevels = array_slice($rankLevels, 0, count(self::RANKS));
        $rankLevels[0] = 1;

        return [
            'levelBase' => $int('level_base', 60, 1),
            'levelStep' => $int('level_step', 20),
            'levelCap' => $int('level_cap', 500, 1),
            'rankLevels' => $rankLevels,
            'ranks' => array_slice(self::RANKS, 0, count($rankLevels)),
            'fireBase' => $int('fire_base', 20),
            'firePerSide' => $int('fire_per_side', 10),
            'fireMin' => $int('fire_min', 10),
            'fireMax' => $int('fire_max', 70),
            'noHints' => $int('no_hints', 10),
            'daily' => $int('daily', 20),
            'streakPerDay' => $int('streak_per_day', 5),
            'streakMaxDays' => $int('streak_max_days', 5),
            'weekGoal' => $int('week_goal', 50),
        ];
    }

    /** Points needed to go from $level to $level + 1. Grows, then stays flat at the cap. */
    public static function xpToNext(int $level): int
    {
        $r = self::rules();

        return min($r['levelCap'], $r['levelBase'] + $r['levelStep'] * (max(1, $level) - 1));
    }

    /** Total points needed to be at $level (0 at level 1). */
    public static function xpForLevel(int $level): int
    {
        $sum = 0;
        for ($i = 1; $i < $level; $i++) {
            $sum += self::xpToNext($i);
        }

        return $sum;
    }

    /** Index into RANKS for a level. */
    public static function rankIndexForLevel(int $level): int
    {
        $index = 0;
        foreach (self::rules()['rankLevels'] as $i => $from) {
            if ($level >= $from) {
                $index = $i;
            }
        }

        return $index;
    }

    /**
     * Where a points total stands: level, how far into it, and the rank.
     *
     * @return array{xp: int, level: int, intoLevel: int, levelSize: int, toGo: int, rank: int, rankKey: string, nextRankLevel: int|null}
     */
    public static function standing(int $xp): array
    {
        $xp = max(0, $xp);
        $level = 1;
        $remaining = $xp;
        $size = self::xpToNext(1);
        while ($remaining >= $size) {
            $remaining -= $size;
            $level++;
            $size = self::xpToNext($level);
        }

        $rules = self::rules();
        $rank = self::rankIndexForLevel($level);

        return [
            'xp' => $xp,
            'level' => $level,
            'intoLevel' => $remaining,
            'levelSize' => $size,
            'toGo' => $size - $remaining,
            'rank' => $rank,
            'rankKey' => $rules['ranks'][$rank],
            'nextRankLevel' => $rules['rankLevels'][$rank + 1] ?? null,
        ];
    }

    /** Points for the fire itself: bigger boards give more. */
    public static function fireXp(int $rows, int $cols): int
    {
        $r = self::rules();
        $side = max($rows, $cols);

        return max($r['fireMin'], min($r['fireMax'], $r['fireBase'] + $r['firePerSide'] * ($side - 5)));
    }

    /**
     * What one solved fire is worth, as the list of lines the result screen
     * counts up one by one.
     *
     * - fire: always, by board size. The one exception: a board where the
     *   hints placed every firebreak gives nothing, so asking for hints
     *   over and over is not a way to collect points.
     * - noHints: only where the server counts hints itself (daily and
     *   campaign); pass hintsUsed = null where it does not.
     * - daily: today's fire.
     * - streak: from the second day in a row, a little more each day up to
     *   streakMaxDays. A missed day simply starts again at no bonus.
     * - week: once, on the fire that reaches the weekly goal.
     *
     * @param  array{rows: int, cols: int, breaks?: int, daily?: bool, hintsUsed?: int|null, streak?: int, weekReached?: bool}  $facts
     * @return array{items: list<array{key: string, xp: int, n?: int}>, total: int}
     */
    public static function reward(array $facts): array
    {
        $r = self::rules();
        $hints = $facts['hintsUsed'] ?? null;
        $breaks = (int) ($facts['breaks'] ?? 0);
        $allHinted = $hints !== null && $breaks > 0 && $hints >= $breaks;

        $items = [];
        $items[] = ['key' => 'fire', 'xp' => $allHinted ? 0 : self::fireXp((int) $facts['rows'], (int) $facts['cols'])];

        if ($hints === 0 && $r['noHints'] > 0) {
            $items[] = ['key' => 'noHints', 'xp' => $r['noHints']];
        }
        if (($facts['daily'] ?? false) && $r['daily'] > 0) {
            $items[] = ['key' => 'daily', 'xp' => $r['daily']];
        }
        $streak = (int) ($facts['streak'] ?? 0);
        if ($streak >= 2 && $r['streakPerDay'] > 0) {
            $items[] = ['key' => 'streak', 'xp' => $r['streakPerDay'] * min($streak, $r['streakMaxDays']), 'n' => $streak];
        }
        if (($facts['weekReached'] ?? false) && $r['weekGoal'] > 0) {
            $items[] = ['key' => 'week', 'xp' => $r['weekGoal']];
        }

        return ['items' => $items, 'total' => array_sum(array_column($items, 'xp'))];
    }
}
