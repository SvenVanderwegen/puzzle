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
     * @var array<string, array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int}>
     */
    public const DIFFICULTIES = [
        'lookout' => ['label' => 'Lookout 5×5', 'rows' => 5, 'cols' => 5, 'breaks' => 4, 'budgetMs' => 4000, 'minClues' => 5],
        'crew' => ['label' => 'Crew 6×6', 'rows' => 6, 'cols' => 6, 'breaks' => 8, 'budgetMs' => 6000, 'minClues' => 8],
        'hotshot' => ['label' => 'Hotshot 7×7', 'rows' => 7, 'cols' => 7, 'breaks' => 12, 'budgetMs' => 9000, 'minClues' => 12],
    ];

    public const DEFAULT_DIFFICULTY = 'lookout';

    /**
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>}
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

        return $this->serialize($difficulty, $result['puzzle']);
    }

    /**
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>}
     */
    private function serialize(string $difficulty, Puzzle $puzzle): array
    {
        $clues = [];
        foreach ($puzzle->clues as $cell => $minute) {
            $clues[] = [$cell, $minute];
        }

        return [
            'difficulty' => $difficulty,
            'rows' => $puzzle->rows,
            'cols' => $puzzle->cols,
            'breaks' => $puzzle->breaks,
            'spark' => $puzzle->spark,
            'clues' => $clues,
        ];
    }
}
