<?php

declare(strict_types=1);

namespace App\Domain\Solves;

/**
 * Authoritative server-side re-check of a complete shading against a board:
 * a pure-PHP BFS mirror of the Python reference (reference/firebreak.py,
 * _burn_verdict/_flat_times). contracts/vectors/burn.v1.jsonl is law — the
 * 509-vector Pest test pins this implementation to the reference.
 *
 * Frozen semantics (contracts/vectors/README.md):
 * - shading is the row-major ASCII bit string, '1' = firebreak;
 * - times are BFS distances from the spark over unshaded cells (unit steps,
 *   neighbors up/down/left/right); -1 = shaded or unreached; if the spark is
 *   shaded the fire never starts and every cell is -1;
 * - check order: spark_shaded -> clue_shaded (row-major) -> wrong_break_count
 *   -> unreachable_cell (row-major) -> clue_time_mismatch (row-major) -> ok.
 */
final class BurnValidator
{
    public function verdict(Board $board, string $shading): BurnVerdict
    {
        $cols = $board->cols;
        $times = $this->times($board, $shading);

        if ($shading[$board->sparkRow * $cols + $board->sparkCol] === '1') {
            return new BurnVerdict(false, BurnVerdictReason::SparkShaded, $times);
        }

        foreach ($board->clues as $clue) {
            if ($shading[$clue['r'] * $cols + $clue['c']] === '1') {
                return new BurnVerdict(false, BurnVerdictReason::ClueShaded, $times);
            }
        }

        if (substr_count($shading, '1') !== $board->breaks) {
            return new BurnVerdict(false, BurnVerdictReason::WrongBreakCount, $times);
        }

        $cellCount = $board->cellCount();

        for ($i = 0; $i < $cellCount; $i++) {
            if ($shading[$i] === '0' && $times[$i] === -1) {
                return new BurnVerdict(false, BurnVerdictReason::UnreachableCell, $times);
            }
        }

        foreach ($board->clues as $clue) {
            if ($times[$clue['r'] * $cols + $clue['c']] !== $clue['m']) {
                return new BurnVerdict(false, BurnVerdictReason::ClueTimeMismatch, $times);
            }
        }

        return new BurnVerdict(true, BurnVerdictReason::Ok, $times);
    }

    /**
     * Row-major burn minutes; -1 = shaded or unreached.
     *
     * @return array<int, int> index r*cols+c, always exactly rows*cols entries
     */
    private function times(Board $board, string $shading): array
    {
        $rows = $board->rows;
        $cols = $board->cols;
        $times = array_fill(0, $rows * $cols, -1);
        $spark = $board->sparkRow * $cols + $board->sparkCol;

        if ($shading[$spark] === '1') {
            return $times;
        }

        $times[$spark] = 0;
        $queue = [$spark];
        $head = 0;

        while ($head < count($queue)) {
            $cell = $queue[$head++];
            $r = intdiv($cell, $cols);
            $c = $cell % $cols;
            $minute = $times[$cell] + 1;

            // Neighbor order up/down/left/right (order does not affect distances).
            foreach ([[$r - 1, $c], [$r + 1, $c], [$r, $c - 1], [$r, $c + 1]] as [$nr, $nc]) {
                if ($nr < 0 || $nr >= $rows || $nc < 0 || $nc >= $cols) {
                    continue;
                }

                $neighbor = $nr * $cols + $nc;

                if ($times[$neighbor] === -1 && $shading[$neighbor] === '0') {
                    $times[$neighbor] = $minute;
                    $queue[] = $neighbor;
                }
            }
        }

        return $times;
    }
}
