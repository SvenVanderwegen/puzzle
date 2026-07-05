<?php

namespace App\Support\Burnfront;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Campaign mode: a fixed 20-level, 5-chapter difficulty ladder (grid size,
 * firebreak density, and clue sparsity all ramp up within each chapter, not
 * just between chapters) plus the XP formulas that turn solves into levels.
 * A player's current level is always derived from their total XP — there is
 * no separately-tracked "unlocked" flag to desync (see CampaignProfile).
 *
 * Also owns the signed run token that binds hint()/solve()/score requests to
 * an incident /campaign/puzzle actually generated (mirrors the daily
 * incident's Crypt-based token in BurnfrontController) — without it, a
 * client could fabricate a trivial spark/clues pair that satisfies
 * Engine::exactCheck() without ever solving a generated board, or claim a
 * hint-free solve after hinting the whole thing.
 */
final class CampaignService
{
    public const TOTAL_LEVELS = 20;

    /**
     * How long a run token (and the hint-count/redemption cache entries
     * keyed off it) stays valid — generous enough that no real solve ever
     * expires mid-attempt, short enough to bound cache growth and the
     * window a leaked token could be replayed in.
     */
    public const TOKEN_TTL_SECONDS = 7200;

    private const CHAPTERS = [
        1 => 'Lookout',
        2 => 'Crew',
        3 => 'Hotshot',
        4 => 'Division Supervisor',
        5 => 'Cold Case',
    ];

    /**
     * Levels 1-4/5-8/9-12/13-16 land exactly on the shipped lookout/crew/
     * hotshot/division PuzzleService::DIFFICULTIES tiers at their last
     * level (already proven to converge in production); 18 lands on
     * coldcase. Every breaksRatio here stays at or under division's 0.266,
     * itself under the empirical CUSTOM_BREAKS_RATIO=0.28 ceiling.
     *
     * @var array<int, array{rows:int,cols:int,breaks:int,minClues:int,budgetMs:int}>
     */
    private const LEVELS = [
        1 => ['rows' => 5, 'cols' => 5, 'breaks' => 3, 'minClues' => 7, 'budgetMs' => 3000],
        2 => ['rows' => 5, 'cols' => 5, 'breaks' => 3, 'minClues' => 6, 'budgetMs' => 3300],
        3 => ['rows' => 5, 'cols' => 5, 'breaks' => 4, 'minClues' => 6, 'budgetMs' => 3700],
        4 => ['rows' => 5, 'cols' => 5, 'breaks' => 4, 'minClues' => 5, 'budgetMs' => 4000],
        5 => ['rows' => 6, 'cols' => 6, 'breaks' => 6, 'minClues' => 10, 'budgetMs' => 4500],
        6 => ['rows' => 6, 'cols' => 6, 'breaks' => 6, 'minClues' => 9, 'budgetMs' => 5000],
        7 => ['rows' => 6, 'cols' => 6, 'breaks' => 7, 'minClues' => 9, 'budgetMs' => 5500],
        8 => ['rows' => 6, 'cols' => 6, 'breaks' => 8, 'minClues' => 8, 'budgetMs' => 6000],
        9 => ['rows' => 7, 'cols' => 7, 'breaks' => 9, 'minClues' => 15, 'budgetMs' => 6500],
        10 => ['rows' => 7, 'cols' => 7, 'breaks' => 10, 'minClues' => 13, 'budgetMs' => 7300],
        11 => ['rows' => 7, 'cols' => 7, 'breaks' => 11, 'minClues' => 13, 'budgetMs' => 8100],
        12 => ['rows' => 7, 'cols' => 7, 'breaks' => 12, 'minClues' => 12, 'budgetMs' => 9000],
        13 => ['rows' => 8, 'cols' => 8, 'breaks' => 13, 'minClues' => 21, 'budgetMs' => 9500],
        14 => ['rows' => 8, 'cols' => 8, 'breaks' => 14, 'minClues' => 19, 'budgetMs' => 11000],
        15 => ['rows' => 8, 'cols' => 8, 'breaks' => 16, 'minClues' => 18, 'budgetMs' => 12500],
        16 => ['rows' => 8, 'cols' => 8, 'breaks' => 17, 'minClues' => 17, 'budgetMs' => 14000],
        17 => ['rows' => 7, 'cols' => 7, 'breaks' => 11, 'minClues' => 9, 'budgetMs' => 10000],
        18 => ['rows' => 7, 'cols' => 7, 'breaks' => 12, 'minClues' => 6, 'budgetMs' => 13000],
        19 => ['rows' => 8, 'cols' => 8, 'breaks' => 15, 'minClues' => 8, 'budgetMs' => 13000],
        20 => ['rows' => 8, 'cols' => 8, 'breaks' => 17, 'minClues' => 5, 'budgetMs' => 15000],
    ];

    /**
     * @return array{label:string,rows:int,cols:int,breaks:int,budgetMs:int,minClues:int,timed:bool,chapterKey:int,chapterLabel:string,levelInChapter:int}|null
     */
    public static function levelConfig(int $level): ?array
    {
        $row = self::LEVELS[$level] ?? null;
        if ($row === null) {
            return null;
        }

        $chapterKey = (int) ceil($level / 4);
        $levelInChapter = $level - ($chapterKey - 1) * 4;
        $chapterLabel = self::CHAPTERS[$chapterKey];

        return [
            'label' => "{$chapterLabel} {$levelInChapter}",
            'rows' => $row['rows'],
            'cols' => $row['cols'],
            'breaks' => $row['breaks'],
            'budgetMs' => $row['budgetMs'],
            'minClues' => $row['minClues'],
            'timed' => true,
            'chapterKey' => $chapterKey,
            'chapterLabel' => $chapterLabel,
            'levelInChapter' => $levelInChapter,
        ];
    }

    /**
     * @return list<array{key:int,label:string,levels:list<int>}>
     */
    public static function chapters(): array
    {
        $chapters = [];
        foreach (self::CHAPTERS as $key => $label) {
            $start = ($key - 1) * 4 + 1;
            $chapters[] = [
                'key' => $key,
                'label' => $label,
                'levels' => range($start, min($start + 3, self::TOTAL_LEVELS)),
            ];
        }

        return $chapters;
    }

    /**
     * XP needed to go from $level to $level+1 — grows with level, so later
     * levels take more solves to clear. A tuning starting point, not gospel;
     * revisit after playtesting the same way PuzzleService's budgetMs was.
     */
    public static function xpToNext(int $level): int
    {
        return 100 + 40 * ($level - 1);
    }

    /** Total XP needed to *be at* $level (0 at level 1). */
    public static function cumulativeXpForLevel(int $level): int
    {
        $sum = 0;
        for ($i = 1; $i < $level; $i++) {
            $sum += self::xpToNext($i);
        }

        return $sum;
    }

    /** Current level for a given XP total, capped at TOTAL_LEVELS. */
    public static function levelForXp(int $totalXp): int
    {
        $level = 1;
        while ($level < self::TOTAL_LEVELS && $totalXp >= self::cumulativeXpForLevel($level + 1)) {
            $level++;
        }

        return $level;
    }

    /**
     * Full-credit (no-hint) XP for a clean solve at $level, set as a
     * quarter of that level's own threshold so pacing (~4 clean solves per
     * level-up) stays roughly constant across the whole curve.
     */
    public static function baseXp(int $level): int
    {
        return (int) ceil(self::xpToNext($level) / 4);
    }

    /**
     * Hints deduct a proportional share of the award; hints used >= this
     * level's firebreak count zeroes it entirely.
     */
    public static function xpAwarded(int $level, int $hintsUsed): int
    {
        $config = self::levelConfig($level);
        if ($config === null) {
            return 0;
        }
        $breaks = $config['breaks'];
        if ($hintsUsed >= $breaks) {
            return 0;
        }

        return (int) round(self::baseXp($level) * (1 - $hintsUsed / $breaks));
    }

    /**
     * Everything a view needs to render this account's campaign standing,
     * derived purely from total_xp — no other stored state exists.
     *
     * @return array{level:int,chapterKey:int,chapterLabel:string,xpIntoLevel:int,xpToNextLevel:int|null,totalXp:int,maxed:bool}
     */
    public static function progressForXp(int $totalXp): array
    {
        $level = self::levelForXp($totalXp);
        $config = self::levelConfig($level);
        // Reaching level 20 isn't the same as clearing it: levelForXp() caps
        // display at TOTAL_LEVELS, so "maxed" has to check XP against the
        // threshold *past* it (xpToNext()/cumulativeXpForLevel() are plain
        // formulas, not bounded by TOTAL_LEVELS, so this is well-defined)
        // — otherwise the campaign reports "complete" the instant a player
        // unlocks the last level, before they've ever played it.
        $maxed = $totalXp >= self::cumulativeXpForLevel(self::TOTAL_LEVELS + 1);

        return [
            'level' => $level,
            'chapterKey' => $config['chapterKey'],
            'chapterLabel' => $config['chapterLabel'],
            'xpIntoLevel' => $totalXp - self::cumulativeXpForLevel($level),
            'xpToNextLevel' => $maxed ? null : self::xpToNext($level),
            'totalXp' => $totalXp,
            'maxed' => $maxed,
        ];
    }

    /**
     * Signs the exact incident /campaign/puzzle just generated (level,
     * spark, clues, plus an issue time for expiry) so hint()/solve()/
     * submitScore() can later recover that same board without trusting
     * whatever spark/clues a request claims. Crypt::encryptString's random
     * IV means encrypting the same payload twice never yields the same
     * string, so the token doubles as a unique per-run identifier for the
     * hint-count and single-redemption cache keys below.
     *
     * @param  list<array{0: int, 1: int}>  $clues
     */
    public static function issueToken(int $level, int $spark, array $clues): string
    {
        return Crypt::encryptString(json_encode([
            'level' => $level,
            'spark' => $spark,
            'clues' => $clues,
            'issuedAt' => now()->timestamp,
        ]));
    }

    /**
     * Recovers the level/spark/clues a run token was issued for, or null if
     * the token is missing, malformed, expired, or names a level that no
     * longer resolves (the level ladder shrinking, in practice never
     * happens, but this stays defensive rather than assume it can't).
     *
     * @return array{level: int, spark: int, clues: array<int, int>}|null
     */
    public static function decodeRun(mixed $tokenRaw): ?array
    {
        if (! is_string($tokenRaw) || $tokenRaw === '') {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($tokenRaw), true);
        } catch (DecryptException) {
            return null;
        }

        if (
            ! is_array($decoded)
            || ! isset($decoded['level'], $decoded['spark'], $decoded['clues'], $decoded['issuedAt'])
            || ! is_int($decoded['level']) || ! is_int($decoded['issuedAt'])
        ) {
            return null;
        }
        if (now()->timestamp - $decoded['issuedAt'] > self::TOKEN_TTL_SECONDS) {
            return null;
        }

        $config = self::levelConfig($decoded['level']);
        if ($config === null) {
            return null;
        }

        $parsed = Engine::parseSparkAndClues($config['rows'] * $config['cols'], $decoded['spark'], $decoded['clues']);
        if ($parsed === null) {
            return null;
        }
        [$spark, $clues] = $parsed;

        return ['level' => $decoded['level'], 'spark' => $spark, 'clues' => $clues];
    }

    /** Cache key for this run's server-tracked hint count (see BurnfrontController::hint()). */
    public static function hintCacheKey(string $token): string
    {
        return 'burnfront:campaign:hints:v1:'.hash('sha256', $token);
    }

    /** Cache key guarding a run token from being redeemed for XP more than once. */
    public static function redeemedCacheKey(string $token): string
    {
        return 'burnfront:campaign:redeemed:v1:'.hash('sha256', $token);
    }
}
