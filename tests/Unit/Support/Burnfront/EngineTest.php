<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\SeededRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Checks the PHP port against the same fixed instance reference/firebreak.py
 * ships as its README example (demo_puzzle()/run_demo()/selftest()), plus a
 * handful of seeded end-to-end generations. Not a byte-for-byte replay of
 * the Python RNG — the point is that the PHP engine independently satisfies
 * the same invariants (unique, deduction-solvable, every break witnessed)
 * the Python and JS ports are held to.
 */
class EngineTest extends TestCase
{
    /**
     * The demo instance from firebreak.py: 5x5, spark at (3,0), 4 breaks.
     * Columns A-E, rows 1-5: spark A4, clues B4=1, B5=2, C3=5, E2=8, D5=8.
     */
    private function demoPuzzle(): Puzzle
    {
        return new Puzzle(
            rows: 5,
            cols: 5,
            spark: 15, // (3,0)
            clues: [
                9 => 8,  // (1,4)
                12 => 5, // (2,2)
                16 => 1, // (3,1)
                21 => 2, // (4,1)
                23 => 8, // (4,3)
            ],
            breaks: 4,
        );
    }

    /** @return array<int,int> the demo's published firebreaks, cell => 1 */
    private function demoBreaks(): array
    {
        return [8 => 1, 11 => 1, 17 => 1, 22 => 1]; // (1,3) (2,1) (3,2) (4,2)
    }

    public function test_demo_puzzle_has_a_unique_solution(): void
    {
        $result = Engine::countSolutions($this->demoPuzzle(), limit: 3);

        $this->assertFalse($result['aborted']);
        $this->assertSame(1, $result['count']);
    }

    public function test_demo_puzzle_is_solvable_by_pure_deduction(): void
    {
        $state = Engine::deductionSolve($this->demoPuzzle());

        $this->assertNotNull($state);

        $shaded = [];
        foreach ($state as $cell => $value) {
            if ($value === Engine::SHADED) {
                $shaded[] = $cell;
            }
        }
        sort($shaded);

        $this->assertSame(array_keys($this->demoBreaks()), $shaded);
    }

    public function test_demo_puzzle_breaks_are_all_witnessed(): void
    {
        $pz = $this->demoPuzzle();

        $this->assertTrue(Engine::breaksWitnessed(
            $pz->cellCount(),
            $pz->adjacency,
            $pz->spark,
            $this->demoBreaks(),
            $pz->clues,
        ));
    }

    public function test_demo_clue_set_is_irredundant(): void
    {
        $pz = $this->demoPuzzle();

        foreach ($pz->clues as $cell => $minute) {
            $trialClues = $pz->clues;
            unset($trialClues[$cell]);
            $trial = new Puzzle($pz->rows, $pz->cols, $pz->spark, $trialClues, $pz->breaks);

            $result = Engine::countSolutions($trial, limit: 3);
            $stillUnique = ! $result['aborted'] && $result['count'] === 1;
            $stillDeducible = Engine::deductionSolve($trial) !== null;

            $this->assertFalse(
                $stillUnique && $stillDeducible,
                "Removing the clue at cell {$cell} should break uniqueness or deducibility."
            );
        }
    }

    #[DataProvider('seedProvider')]
    public function test_generated_puzzles_are_unique_deducible_and_witnessed(int $seed): void
    {
        $result = Engine::generate(5, 5, 4, [
            'random' => new SeededRandom($seed),
            'budgetMs' => 4000,
        ]);

        $puzzle = $result['puzzle'];

        $count = Engine::countSolutions($puzzle, limit: 3);
        $this->assertFalse($count['aborted']);
        $this->assertSame(1, $count['count']);

        $state = Engine::deductionSolve($puzzle);
        $this->assertNotNull($state);

        foreach ($state as $cell => $value) {
            $this->assertSame($result['solution'][$cell], $value);
        }

        $this->assertTrue(Engine::breaksWitnessed(
            $puzzle->cellCount(),
            $puzzle->adjacency,
            $puzzle->spark,
            array_map(fn ($v) => $v === Engine::SHADED ? 1 : 0, $result['solution']),
            $puzzle->clues,
        ));
    }

    public static function seedProvider(): array
    {
        return [[1], [2], [3], [4], [5]];
    }

    public function test_next_deduction_finds_a_correct_forced_cell_from_a_fresh_state(): void
    {
        $pz = $this->demoPuzzle();
        $breaks = $this->demoBreaks();

        $result = Engine::nextDeduction($pz, Engine::initialState($pz));

        $this->assertSame('forced', $result['status']);
        if ($result['value'] === Engine::SHADED) {
            $this->assertArrayHasKey($result['cell'], $breaks);
        } else {
            $this->assertArrayNotHasKey($result['cell'], $breaks);
        }
    }

    public function test_next_deduction_reports_complete_once_fully_solved(): void
    {
        $pz = $this->demoPuzzle();
        $state = Engine::deductionSolve($pz);

        $result = Engine::nextDeduction($pz, $state);

        $this->assertSame(['status' => 'complete'], $result);
    }

    public function test_next_deduction_reports_contradiction_when_too_many_cells_are_shaded(): void
    {
        $pz = $this->demoPuzzle();
        $state = Engine::initialState($pz);
        foreach ([0, 1, 2, 3, 4] as $cell) {
            $state[$cell] = Engine::SHADED; // 5 shaded cells, but the puzzle only allows 4
        }

        $result = Engine::nextDeduction($pz, $state);

        $this->assertSame(['status' => 'contradiction'], $result);
    }
}
