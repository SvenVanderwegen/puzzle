<?php

declare(strict_types=1);

namespace App\Domain\Solves;

/**
 * Immutable burnfront.puzzle/1 `board` object (contracts/schemas/puzzle.v1.json,
 * mirrored by #/components/schemas/Board in contracts/openapi.yaml).
 *
 * Clues are normalized to row-major (r, c) order on construction — the frozen
 * check order in contracts/vectors/README.md scans clues row-major.
 */
final class Board
{
    /**
     * @param  list<array{r: int, c: int, m: int}>  $clues  row-major sorted
     */
    private function __construct(
        public readonly int $rows,
        public readonly int $cols,
        public readonly int $sparkRow,
        public readonly int $sparkCol,
        public readonly int $breaks,
        public readonly array $clues,
    ) {}

    /**
     * Validate an untrusted spec (endless_spec submissions, imported content)
     * against the frozen board shape. additionalProperties: false is enforced.
     *
     * @param  array<array-key, mixed>  $spec
     */
    public static function fromArray(array $spec): self
    {
        $keys = array_keys($spec);
        sort($keys);

        if ($keys !== ['breaks', 'clues', 'cols', 'rows', 'spark']) {
            throw new InvalidBoardSpec('board must have exactly the keys rows, cols, spark, breaks, clues');
        }

        $rows = $spec['rows'];
        $cols = $spec['cols'];

        if (! is_int($rows) || $rows < 3 || $rows > 12 || ! is_int($cols) || $cols < 3 || $cols > 12) {
            throw new InvalidBoardSpec('rows and cols must be integers between 3 and 12');
        }

        $spark = $spec['spark'];

        if (! is_array($spark) || array_keys($spark) !== [0, 1]
            || ! is_int($spark[0]) || ! is_int($spark[1])
            || $spark[0] < 0 || $spark[0] >= $rows || $spark[1] < 0 || $spark[1] >= $cols) {
            throw new InvalidBoardSpec('spark must be an in-bounds [r, c] pair');
        }

        $breaks = $spec['breaks'];

        if (! is_int($breaks) || $breaks < 1 || $breaks >= $rows * $cols) {
            throw new InvalidBoardSpec('breaks must be an integer >= 1 and smaller than the cell count');
        }

        $rawClues = $spec['clues'];

        if (! is_array($rawClues) || $rawClues === [] || ! array_is_list($rawClues)) {
            throw new InvalidBoardSpec('clues must be a non-empty list');
        }

        $clues = [];
        $seen = [];

        foreach ($rawClues as $clue) {
            if (! is_array($clue)) {
                throw new InvalidBoardSpec('each clue must be an {r, c, m} object');
            }

            $clueKeys = array_keys($clue);
            sort($clueKeys);

            if ($clueKeys !== ['c', 'm', 'r']) {
                throw new InvalidBoardSpec('each clue must have exactly the keys r, c, m');
            }

            $r = $clue['r'];
            $c = $clue['c'];
            $m = $clue['m'];

            if (! is_int($r) || ! is_int($c) || ! is_int($m)
                || $r < 0 || $r >= $rows || $c < 0 || $c >= $cols || $m < 1) {
                throw new InvalidBoardSpec('clue out of bounds or minute < 1');
            }

            $cell = $r * $cols + $c;

            if (isset($seen[$cell])) {
                throw new InvalidBoardSpec('duplicate clue cell');
            }

            $seen[$cell] = true;
            $clues[] = ['r' => $r, 'c' => $c, 'm' => $m];
        }

        if (isset($seen[$spark[0] * $cols + $spark[1]])) {
            throw new InvalidBoardSpec('the spark cell cannot carry a clue');
        }

        usort($clues, fn (array $a, array $b): int => [$a['r'], $a['c']] <=> [$b['r'], $b['c']]);

        return new self($rows, $cols, $spark[0], $spark[1], $breaks, $clues);
    }

    public function cellCount(): int
    {
        return $this->rows * $this->cols;
    }

    /**
     * Canonical board object (clues row-major), suitable for jsonb storage and
     * for the origin-fallback `puzzle` field of GET /daily/{date}.
     *
     * @return array{rows: int, cols: int, spark: array{int, int}, breaks: int, clues: list<array{r: int, c: int, m: int}>}
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'cols' => $this->cols,
            'spark' => [$this->sparkRow, $this->sparkCol],
            'breaks' => $this->breaks,
            'clues' => $this->clues,
        ];
    }
}
