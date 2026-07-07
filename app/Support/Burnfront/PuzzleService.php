<?php

namespace App\Support\Burnfront;

use InvalidArgumentException;

/**
 * The seam between HTTP and the Engine: difficulty tiers, puzzle
 * generation, and wire-format serialization. generateDaily() seeds
 * generate() from the date (plus a caller-supplied secret — see its
 * docblock) and BurnfrontController caches the result; BurnfrontController
 * also validates a claimed solve against that same clue set.
 */
final class PuzzleService
{
    /**
     * @var array<string, array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}>
     */
    public const DIFFICULTIES = [
        'lookout' => ['label' => 'Lookout 5×5', 'rows' => 5, 'cols' => 5, 'breaks' => 4, 'budgetMs' => 4000, 'minClues' => 5, 'timed' => true],
        'crew' => ['label' => 'Crew 6×6', 'rows' => 6, 'cols' => 6, 'breaks' => 8, 'budgetMs' => 6000, 'minClues' => 8, 'timed' => true],
        'hotshot' => ['label' => 'Hotshot 7×7', 'rows' => 7, 'cols' => 7, 'breaks' => 12, 'budgetMs' => 9000, 'minClues' => 12, 'timed' => true],
        'division' => ['label' => 'Division Supervisor 8×8', 'rows' => 8, 'cols' => 8, 'breaks' => 17, 'budgetMs' => 14000, 'minClues' => 17, 'timed' => true],
        'coldcase' => ['label' => 'Cold Case 7×7', 'rows' => 7, 'cols' => 7, 'breaks' => 12, 'budgetMs' => 13000, 'minClues' => 6, 'timed' => false],
    ];

    public const DEFAULT_DIFFICULTY = 'lookout';

    /**
     * Bounds for the player-chosen custom grid. These aren't a difficulty
     * curve decision — they exist because Engine::witnessedTerrain() has no
     * time budget of its own and can spin close to forever repairing terrain
     * once the breaks-to-cells ratio gets too dense for a grid this small
     * (verified empirically: a 4x4 grid with 5 of its 16 cells shaded — under
     * 32% — already hangs). CUSTOM_BREAKS_RATIO stays comfortably under every
     * failure point observed across 4x4 through 10x10.
     */
    public const CUSTOM_MIN_DIM = 4;

    public const CUSTOM_MAX_DIM = 10;

    public const CUSTOM_MIN_BREAKS = 2;

    public const CUSTOM_BREAKS_RATIO = 0.28;

    /**
     * Kept out of DIFFICULTIES on purpose: the daily incident is a distinct
     * mode (one shared, date-seeded board), not a tier a player picks from
     * the difficulty selector — /puzzle?difficulty=daily must keep 422ing.
     *
     * @var array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}
     */
    private const DAILY_TIER = ['label' => 'Daily 6×6', 'rows' => 6, 'cols' => 6, 'breaks' => 8, 'budgetMs' => 6000, 'minClues' => 8, 'timed' => true];

    /**
     * @return array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}|null
     */
    public static function tierConfig(string $difficulty): ?array
    {
        if ($difficulty === 'daily') {
            return self::DAILY_TIER;
        }

        return self::DIFFICULTIES[$difficulty] ?? null;
    }

    /**
     * The largest number of firebreaks that's safe to generate for a given
     * custom grid — see CUSTOM_BREAKS_RATIO for why this cap exists.
     */
    public static function customMaxBreaks(int $rows, int $cols): int
    {
        return max(self::CUSTOM_MIN_BREAKS, (int) floor($rows * $cols * self::CUSTOM_BREAKS_RATIO));
    }

    /**
     * Validates and builds a tier-shaped config for a player-chosen custom
     * grid, or null if any dimension or the break count falls outside the
     * bounds above. budgetMs scales with cell count the same way the named
     * tiers already do, capped at the largest budget any shipped tier uses.
     *
     * @return array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}|null
     */
    public static function customConfig(int $rows, int $cols, int $breaks): ?array
    {
        if ($rows < self::CUSTOM_MIN_DIM || $rows > self::CUSTOM_MAX_DIM) {
            return null;
        }
        if ($cols < self::CUSTOM_MIN_DIM || $cols > self::CUSTOM_MAX_DIM) {
            return null;
        }
        if ($breaks < self::CUSTOM_MIN_BREAKS || $breaks > self::customMaxBreaks($rows, $cols)) {
            return null;
        }

        $cells = $rows * $cols;

        return [
            'label' => "Custom {$rows}×{$cols}",
            'rows' => $rows,
            'cols' => $cols,
            'breaks' => $breaks,
            'budgetMs' => (int) min(14000, max(4000, round($cells * 220))),
            'minClues' => $breaks,
            'timed' => true,
        ];
    }

    /**
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string}
     */
    public function generate(string $difficulty, ?callable $random = null): array
    {
        $config = self::DIFFICULTIES[$difficulty] ?? null;
        if ($config === null) {
            throw new InvalidArgumentException("Unknown difficulty [{$difficulty}].");
        }

        return $this->generateFromConfig($difficulty, $config, $random);
    }

    /**
     * Same as generate(), but for a config built by customConfig() rather
     * than looked up from DIFFICULTIES — kept as its own method instead of
     * an overload on generate() so that method's public signature (and the
     * meaning of its second argument) never changes for existing callers.
     *
     * @param  array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}  $config
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string}
     */
    public function generateCustom(array $config, ?callable $random = null): array
    {
        return $this->generateFromConfig('custom', $config, $random);
    }

    /**
     * @param  array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}  $config
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string}
     */
    private function generateFromConfig(string $difficulty, array $config, ?callable $random): array
    {
        $result = Engine::generate($config['rows'], $config['cols'], $config['breaks'], [
            'random' => $random,
            'budgetMs' => $config['budgetMs'],
            'minClues' => $config['minClues'],
        ]);

        $namingRand = fn () => mt_rand() / mt_getrandmax();

        return $this->serialize($difficulty, $result['puzzle'], $namingRand);
    }

    /**
     * Same as generate(), but for a config built by CampaignService::levelConfig()
     * rather than looked up from DIFFICULTIES — kept as its own method for the
     * same reason generateCustom() is: existing callers' signatures never
     * change. Campaign levels are never seeded (unlike the daily incident) —
     * a player replays their current level repeatedly until leveling up, so a
     * fixed shared solution would trivialize repeat attempts.
     *
     * @param  array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}  $config
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string}
     */
    public function generateCampaign(array $config, ?callable $random = null): array
    {
        return $this->generateFromConfig('campaign', $config, $random);
    }

    /**
     * The daily incident: terrain and naming are each seeded independently
     * from the given date, so the same date always reproduces the same
     * board and the same name/blurb (naming's seed is a distinct hash so it
     * never depends on how many random draws terrain generation consumed).
     *
     * $secret is folded into both seeds alongside the date. Without it, the
     * seed would be `crc32(namespace|date)` — reproducible by anyone who has
     * this (public) source tree, since a date isn't a secret. The caller
     * (BurnfrontController) passes the deployment's APP_KEY, so precomputing
     * a future date's incident requires that deployment's secret, not just
     * the algorithm. Defaults to '' only so existing direct/unit callers of
     * this method keep working without a Laravel app context to pull a key
     * from; production always passes the real key.
     *
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string, date: string}
     */
    public function generateDaily(string $date, string $secret = ''): array
    {
        $config = self::DAILY_TIER;

        $terrainRand = new SeededRandom(self::seedFor('burnfront-daily-terrain-v1', $date, $secret));
        $result = Engine::generate($config['rows'], $config['cols'], $config['breaks'], [
            'random' => $terrainRand,
            'budgetMs' => $config['budgetMs'],
            'minClues' => $config['minClues'],
        ]);

        $namingRand = new SeededRandom(self::seedFor('burnfront-daily-name-v1', $date, $secret));
        $payload = $this->serialize('daily', $result['puzzle'], $namingRand);
        $payload['date'] = $date;

        return $payload;
    }

    private static function seedFor(string $namespace, string $date, string $secret = ''): int
    {
        return crc32("{$namespace}|{$date}|{$secret}");
    }

    /**
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string}
     */
    private function serialize(string $difficulty, Puzzle $puzzle, callable $namingRand): array
    {
        $clues = [];
        foreach ($puzzle->clues as $cell => $minute) {
            $clues[] = [$cell, $minute];
        }

        $incident = IncidentNamer::generate($namingRand);

        return [
            'difficulty' => $difficulty,
            'rows' => $puzzle->rows,
            'cols' => $puzzle->cols,
            'breaks' => $puzzle->breaks,
            'spark' => $puzzle->spark,
            'clues' => $clues,
            'name' => $incident['name'],
            'blurb' => $incident['blurb'],
        ];
    }
}
