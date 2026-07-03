<?php

namespace App\Support\Burnfront;

/**
 * A Burnfront incident: an R x C grid, a spark cell, a set of clue cells
 * (cell index => the minute fire must reach it), and the exact number of
 * firebreaks the reconstruction must place. Faithful port of the `Puzzle`
 * class in reference/firebreak.py, using flat row-major cell indices
 * (r * cols + c) instead of (r, c) tuples.
 */
final class Puzzle
{
    /** @var array<int, list<int>> */
    public readonly array $adjacency;

    /**
     * @param  array<int, int>  $clues  cell index => burn minute
     */
    public function __construct(
        public readonly int $rows,
        public readonly int $cols,
        public readonly int $spark,
        public readonly array $clues,
        public readonly int $breaks,
    ) {
        $this->adjacency = self::buildAdjacency($rows, $cols);
    }

    public function cellCount(): int
    {
        return $this->rows * $this->cols;
    }

    /** @return array<int, list<int>> */
    public static function buildAdjacency(int $rows, int $cols): array
    {
        $adjacency = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $neighbors = [];
                if ($r > 0) {
                    $neighbors[] = ($r - 1) * $cols + $c;
                }
                if ($r < $rows - 1) {
                    $neighbors[] = ($r + 1) * $cols + $c;
                }
                if ($c > 0) {
                    $neighbors[] = $r * $cols + $c - 1;
                }
                if ($c < $cols - 1) {
                    $neighbors[] = $r * $cols + $c + 1;
                }
                $adjacency[] = $neighbors;
            }
        }

        return $adjacency;
    }
}
