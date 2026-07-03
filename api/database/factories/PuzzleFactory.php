<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Puzzle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test puzzles built on burn vector boards (contracts/vectors/burn.v1.jsonl,
 * burn-0001): board 3x3, spark [2,0], 2 breaks, clue {r:2,c:2,m:6}. The known
 * valid shading is '000010010'; solution_sha256 matches it, so validator and
 * corruption-tripwire behavior in tests mirror production content.
 *
 * @extends Factory<Puzzle>
 */
class PuzzleFactory extends Factory
{
    public const VALID_SHADING = '000010010';

    /**
     * @var array{rows: int, cols: int, spark: array{int, int}, breaks: int, clues: list<array{r: int, c: int, m: int}>}
     */
    public const BOARD = [
        'rows' => 3,
        'cols' => 3,
        'spark' => [2, 0],
        'breaks' => 2,
        'clues' => [['r' => 2, 'c' => 2, 'm' => 6]],
    ];

    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'id' => sprintf('bf1-3x3-%06d', $n),
            'spec' => self::BOARD,
            'rows' => 3,
            'cols' => 3,
            'n_breaks' => 2,
            'grade_tier' => 'lookout',
            'grade_score' => 4,
            'solution_sha256' => hash('sha256', self::VALID_SHADING),
            'gen_version' => 'gen-test-1',
            'content_version' => 'v20260701-1',
            'pack_id' => null,
            'imported_at' => now(),
        ];
    }
}
