<?php

namespace App\Support\Burnfront;

use InvalidArgumentException;

/**
 * The seam between HTTP and the Engine: difficulty tiers, puzzle
 * generation, and wire-format serialization. Deliberately thin — a future
 * daily-puzzle feature would seed generate() from the date and cache the
 * result here; a scoreboard would validate a claimed solve against the
 * same clue set this class hands out. Neither exists yet.
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
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string}
     */
    public function generate(string $difficulty, ?callable $random = null): array
    {
        $config = self::DIFFICULTIES[$difficulty] ?? null;
        if ($config === null) {
            throw new InvalidArgumentException("Unknown difficulty [{$difficulty}].");
        }

        $result = Engine::generate($config['rows'], $config['cols'], $config['breaks'], [
            'random' => $random,
            'budgetMs' => $config['budgetMs'],
            'minClues' => $config['minClues'],
        ]);

        $namingRand = fn () => mt_rand() / mt_getrandmax();

        return $this->serialize($difficulty, $result['puzzle'], $namingRand);
    }

    /**
     * The daily incident: terrain and naming are each seeded independently
     * from the given date, so the same date always reproduces the same
     * board and the same name/blurb (naming's seed is a distinct hash so it
     * never depends on how many random draws terrain generation consumed).
     *
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string, date: string}
     */
    public function generateDaily(string $date): array
    {
        $config = self::DAILY_TIER;

        $terrainRand = new SeededRandom(self::seedFor('burnfront-daily-terrain-v1', $date));
        $result = Engine::generate($config['rows'], $config['cols'], $config['breaks'], [
            'random' => $terrainRand,
            'budgetMs' => $config['budgetMs'],
            'minClues' => $config['minClues'],
        ]);

        $namingRand = new SeededRandom(self::seedFor('burnfront-daily-name-v1', $date));
        $payload = $this->serialize('daily', $result['puzzle'], $namingRand);
        $payload['date'] = $date;

        return $payload;
    }

    private static function seedFor(string $namespace, string $date): int
    {
        return crc32("{$namespace}|{$date}");
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
