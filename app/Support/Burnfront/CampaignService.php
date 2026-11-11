<?php

namespace App\Support\Burnfront;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Campaign mode: an endless row of numbered levels. Every level is one
 * freshly generated fire; contain it and the next level opens. A player's
 * level is simply the campaign fires they have contained plus one
 * (levelFor()), so there is no separately-tracked "unlocked" flag to
 * desync (see CampaignProfile), and no last level.
 *
 * What kind of board a level gets comes from a 20-rung, 5-chapter
 * difficulty ladder (grid size, firebreak density, and clue sparsity all
 * ramp up within each chapter, not just between chapters). rungFor()
 * spreads the levels over those rungs: a few levels per rung on the way
 * up, then round and round the hardest rungs for ever.
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
    public const TOTAL_RUNGS = 20;

    /**
     * How many levels in a row play on each rung of a chapter, on the way
     * up: the small boards pass quickly, the big ones stay a while. With
     * 2, 3, 4, 5 and 5 levels per rung, the way up takes 76 levels.
     *
     * @var array<int, int>
     */
    private const LEVELS_PER_RUNG = [1 => 2, 2 => 3, 3 => 4, 4 => 5, 5 => 5];

    /** Past the way up, levels walk round this many of the hardest rungs. */
    private const TOP_RUNGS = 8;

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
     * The board for one rung of the ladder (1 to TOTAL_RUNGS), not for a
     * player's level: see boardFor() for that.
     *
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
                'levels' => range($start, min($start + 3, self::TOTAL_RUNGS)),
            ];
        }

        return $chapters;
    }

    /** The level a player is on: one past the campaign fires they have contained. */
    public static function levelFor(int $puzzlesSolved): int
    {
        return max(0, $puzzlesSolved) + 1;
    }

    /**
     * Which rung of the ladder level $level plays on. Levels never stop.
     * On the way up each rung lasts a few levels (LEVELS_PER_RUNG); after
     * the last rung the campaign keeps going by walking round its hardest
     * rungs (8 by 8 boards and the boards with few numbers), one step per
     * level, so two levels in a row are never the same kind.
     */
    public static function rungFor(int $level): int
    {
        $level = max(1, $level);
        $first = 1;
        for ($rung = 1; $rung <= self::TOTAL_RUNGS; $rung++) {
            $lasts = self::LEVELS_PER_RUNG[(int) ceil($rung / 4)];
            if ($level < $first + $lasts) {
                return $rung;
            }
            $first += $lasts;
        }

        return self::TOTAL_RUNGS - self::TOP_RUNGS + 1 + (($level - $first) % self::TOP_RUNGS);
    }

    /** The first level that is past the way up (from here the hardest rungs repeat). */
    public static function firstEndlessLevel(): int
    {
        return 4 * array_sum(self::LEVELS_PER_RUNG) + 1;
    }

    /**
     * The kind of board level $level gets: levelConfig() of its rung.
     *
     * @return array{label:string,rows:int,cols:int,breaks:int,budgetMs:int,minClues:int,timed:bool,chapterKey:int,chapterLabel:string,levelInChapter:int}
     */
    public static function boardFor(int $level): array
    {
        return self::levelConfig(self::rungFor($level));
    }

    /**
     * Signs the exact incident /campaign/puzzle just generated (its rung,
     * kept under the key 'level',
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
