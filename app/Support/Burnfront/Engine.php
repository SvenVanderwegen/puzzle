<?php

namespace App\Support\Burnfront;

/**
 * Server-side port of reference/firebreak.py's core engine (also ported to
 * JS in reference/index.html): BFS burn times, the sound feasibility test,
 * the exact-uniqueness solver, the no-guessing deduction solver, and the
 * generator that repairs random terrain until every firebreak is witnessed
 * by the clues, then greedily strips clues to a minimal irredundant set.
 *
 * Cell state values: 0 unknown, 1 open, 2 shaded (firebreak).
 */
final class Engine
{
    public const UNKNOWN = 0;

    public const OPEN = 1;

    public const SHADED = 2;

    /**
     * BFS distances from $spark over cells for which $passable($cell) holds.
     * Unreachable cells (including $spark itself, if impassable) are -1.
     *
     * @param  array<int, list<int>>  $adjacency
     * @return array<int, int>
     */
    public static function bfs(int $cellCount, array $adjacency, int $spark, callable $passable): array
    {
        $dist = array_fill(0, $cellCount, -1);
        if (! $passable($spark)) {
            return $dist;
        }
        $dist[$spark] = 0;
        $queue = [$spark];
        $head = 0;
        while ($head < count($queue)) {
            $x = $queue[$head++];
            foreach ($adjacency[$x] as $y) {
                if ($dist[$y] < 0 && $passable($y)) {
                    $dist[$y] = $dist[$x] + 1;
                    $queue[] = $y;
                }
            }
        }

        return $dist;
    }

    /** @return array<int, int> */
    public static function initialState(Puzzle $pz): array
    {
        $state = array_fill(0, $pz->cellCount(), self::UNKNOWN);
        $state[$pz->spark] = self::OPEN;
        foreach ($pz->clues as $cell => $minute) {
            $state[$cell] = self::OPEN;
        }

        return $state;
    }

    /**
     * Sound feasibility test for a partial assignment: never rejects a
     * state that can still be completed to a real solution.
     *
     * @param  array<int, int>  $state
     */
    public static function feasible(Puzzle $pz, array $state): bool
    {
        $shaded = 0;
        $unknown = 0;
        foreach ($state as $v) {
            if ($v === self::SHADED) {
                $shaded++;
            } elseif ($v === self::UNKNOWN) {
                $unknown++;
            }
        }
        if ($shaded > $pz->breaks) {
            return false;
        }
        if ($shaded + $unknown < $pz->breaks) {
            return false;
        }

        // Optimistic: unknowns treated as open. Real times can only be
        // larger, so a clue must satisfy d_opt(c) <= v.
        $n = $pz->cellCount();
        $dOpt = self::bfs($n, $pz->adjacency, $pz->spark, fn ($i) => $state[$i] !== self::SHADED);
        foreach ($pz->clues as $cell => $minute) {
            $d = $dOpt[$cell];
            if ($d < 0 || $d > $minute) {
                return false;
            }
        }
        foreach ($state as $i => $v) {
            if ($v === self::OPEN && $dOpt[$i] < 0) {
                return false;
            }
        }

        // Pessimistic: paths through known-open cells only. Fire provably
        // travels at least this fast, so a clue must satisfy d_pes(c) >= v.
        $dPes = self::bfs($n, $pz->adjacency, $pz->spark, fn ($i) => $state[$i] === self::OPEN);
        foreach ($pz->clues as $cell => $minute) {
            $d = $dPes[$cell];
            if ($d >= 0 && $d < $minute) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<int, int>  $state */
    public static function exactCheck(Puzzle $pz, array $state): bool
    {
        $shaded = 0;
        foreach ($state as $v) {
            if ($v === self::UNKNOWN) {
                return false;
            }
            if ($v === self::SHADED) {
                $shaded++;
            }
        }
        if ($shaded !== $pz->breaks) {
            return false;
        }
        $d = self::bfs($pz->cellCount(), $pz->adjacency, $pz->spark, fn ($i) => $state[$i] === self::OPEN);
        foreach ($state as $i => $v) {
            if ($v === self::OPEN && $d[$i] < 0) {
                return false;
            }
        }
        foreach ($pz->clues as $cell => $minute) {
            if ($d[$cell] !== $minute) {
                return false;
            }
        }

        return true;
    }

    /**
     * Prefer an unknown cell sitting on a tight optimistic path to some
     * clue; walk one shortest path back toward the spark. Falls back to
     * any unknown adjacent to an open cell, then to any unknown.
     *
     * @param  array<int, int>  $state
     */
    private static function pickBranchCell(Puzzle $pz, array $state): ?int
    {
        $n = $pz->cellCount();
        $dOpt = self::bfs($n, $pz->adjacency, $pz->spark, fn ($i) => $state[$i] !== self::SHADED);
        foreach ($pz->clues as $cell => $minute) {
            if (($dOpt[$cell] ?? -1) !== $minute) {
                continue;
            }
            $x = $cell;
            while ($x !== $pz->spark) {
                $next = -1;
                foreach ($pz->adjacency[$x] as $y) {
                    if (($dOpt[$y] ?? -2) === $dOpt[$x] - 1) {
                        if ($state[$y] === self::UNKNOWN) {
                            return $y;
                        }
                        $next = $y;
                        break;
                    }
                }
                if ($next < 0) {
                    break;
                }
                $x = $next;
            }
        }

        $fallback = null;
        foreach ($state as $i => $v) {
            if ($v !== self::UNKNOWN) {
                continue;
            }
            foreach ($pz->adjacency[$i] as $y) {
                if ($state[$y] === self::OPEN) {
                    return $i;
                }
            }
            if ($fallback === null) {
                $fallback = $i;
            }
        }

        return $fallback;
    }

    /**
     * Exact solution count (up to $limit), the uniqueness oracle. Sound
     * pruning means the count is exact whenever the node budget isn't
     * exhausted; `aborted` signals "don't know" so callers stay conservative.
     *
     * $deadlineMs, if given, is an absolute `microtime(true) * 1000`
     * timestamp: the search also aborts once past it, independent of the
     * node budget. A single call can otherwise run for as long as the node
     * budget takes regardless of how much of a caller's own time budget is
     * left — on a server, that can tie up a worker well past the deadline
     * the caller thought it was enforcing (see generate()).
     *
     * @return array{count: int, aborted: bool}
     */
    public static function countSolutions(Puzzle $pz, int $limit = 2, int $nodeBudget = 300000, ?float $deadlineMs = null): array
    {
        $state = self::initialState($pz);
        $count = 0;
        $aborted = false;
        $budget = $nodeBudget;
        $sinceDeadlineCheck = 0;

        $dfs = function () use (&$dfs, &$state, &$count, &$aborted, &$budget, &$sinceDeadlineCheck, $pz, $limit, $deadlineMs): void {
            if ($count >= $limit || $aborted) {
                return;
            }
            if (--$budget < 0) {
                $aborted = true;

                return;
            }
            if ($deadlineMs !== null && (++$sinceDeadlineCheck & 511) === 0 && microtime(true) * 1000 >= $deadlineMs) {
                $aborted = true;

                return;
            }
            if (! self::feasible($pz, $state)) {
                return;
            }

            $shaded = 0;
            $unknown = 0;
            foreach ($state as $v) {
                if ($v === self::SHADED) {
                    $shaded++;
                } elseif ($v === self::UNKNOWN) {
                    $unknown++;
                }
            }

            if ($unknown > 0 && ($shaded === $pz->breaks || $shaded + $unknown === $pz->breaks)) {
                $fill = $shaded === $pz->breaks ? self::OPEN : self::SHADED;
                $filled = [];
                foreach ($state as $i => $v) {
                    if ($v === self::UNKNOWN) {
                        $state[$i] = $fill;
                        $filled[] = $i;
                    }
                }
                $dfs();
                foreach ($filled as $i) {
                    $state[$i] = self::UNKNOWN;
                }

                return;
            }

            if ($unknown === 0) {
                if (self::exactCheck($pz, $state)) {
                    $count++;
                }

                return;
            }

            $x = self::pickBranchCell($pz, $state);
            $state[$x] = self::SHADED;
            $dfs();
            $state[$x] = self::UNKNOWN;
            if ($count >= $limit || $aborted) {
                return;
            }
            $state[$x] = self::OPEN;
            $dfs();
            $state[$x] = self::UNKNOWN;
        };

        $dfs();

        return ['count' => $count, 'aborted' => $aborted];
    }

    /**
     * No-backtracking, single-cell-forced solver: repeatedly assigns any
     * cell for which one of the two states fails feasibility. Success
     * certifies the puzzle needs no guessing. Returns null on contradiction
     * or if it stalls before every cell is assigned.
     *
     * @return array<int, int>|null
     */
    public static function deductionSolve(Puzzle $pz): ?array
    {
        $state = self::initialState($pz);
        $progress = true;

        while ($progress) {
            $progress = false;
            $shaded = 0;
            $unknown = 0;
            foreach ($state as $v) {
                if ($v === self::SHADED) {
                    $shaded++;
                } elseif ($v === self::UNKNOWN) {
                    $unknown++;
                }
            }
            if ($unknown === 0) {
                break;
            }
            if ($shaded === $pz->breaks) {
                foreach ($state as $i => $v) {
                    if ($v === self::UNKNOWN) {
                        $state[$i] = self::OPEN;
                    }
                }
                break;
            }
            if ($shaded + $unknown === $pz->breaks) {
                foreach ($state as $i => $v) {
                    if ($v === self::UNKNOWN) {
                        $state[$i] = self::SHADED;
                    }
                }
                break;
            }

            foreach ($state as $i => $v) {
                if ($v !== self::UNKNOWN) {
                    continue;
                }
                $state[$i] = self::OPEN;
                $okOpen = self::feasible($pz, $state);
                $state[$i] = self::SHADED;
                $okShaded = self::feasible($pz, $state);
                $state[$i] = self::UNKNOWN;

                if (! $okOpen && ! $okShaded) {
                    return null;
                }
                if (! $okOpen) {
                    $state[$i] = self::SHADED;
                    $progress = true;
                } elseif (! $okShaded) {
                    $state[$i] = self::OPEN;
                    $progress = true;
                }
            }
        }

        foreach ($state as $v) {
            if ($v === self::UNKNOWN) {
                return null;
            }
        }

        return self::exactCheck($pz, $state) ? $state : null;
    }

    /**
     * A single step of deductionSolve's reasoning, for the hint system: given
     * a partial state (typically initialState() plus whichever cells the
     * player has already committed as firebreaks), find one cell whose value
     * is forced — without touching any of the others. Unlike deductionSolve,
     * this never guesses ahead or chains cells found earlier in the same
     * call; it reports the first single-cell deduction available from
     * exactly what's already committed, which is what a player asking "what
     * can I prove right now" wants. status is 'forced' (cell/value set),
     * 'contradiction', 'complete', or 'stuck'.
     *
     * @param  array<int, int>  $state
     * @return array{status: string, cell?: int, value?: int}
     */
    public static function nextDeduction(Puzzle $pz, array $state): array
    {
        if (! self::feasible($pz, $state)) {
            return ['status' => 'contradiction'];
        }

        $shaded = 0;
        $unknown = 0;
        foreach ($state as $v) {
            if ($v === self::SHADED) {
                $shaded++;
            } elseif ($v === self::UNKNOWN) {
                $unknown++;
            }
        }

        if ($unknown === 0) {
            return ['status' => 'complete'];
        }

        if ($shaded === $pz->breaks) {
            foreach ($state as $i => $v) {
                if ($v === self::UNKNOWN) {
                    return ['status' => 'forced', 'cell' => $i, 'value' => self::OPEN];
                }
            }
        }

        if ($shaded + $unknown === $pz->breaks) {
            foreach ($state as $i => $v) {
                if ($v === self::UNKNOWN) {
                    return ['status' => 'forced', 'cell' => $i, 'value' => self::SHADED];
                }
            }
        }

        foreach ($state as $i => $v) {
            if ($v !== self::UNKNOWN) {
                continue;
            }
            $state[$i] = self::OPEN;
            $okOpen = self::feasible($pz, $state);
            $state[$i] = self::SHADED;
            $okShaded = self::feasible($pz, $state);
            $state[$i] = self::UNKNOWN;

            if (! $okOpen && ! $okShaded) {
                return ['status' => 'contradiction'];
            }
            if (! $okOpen) {
                return ['status' => 'forced', 'cell' => $i, 'value' => self::SHADED];
            }
            if (! $okShaded) {
                return ['status' => 'forced', 'cell' => $i, 'value' => self::OPEN];
            }
        }

        return ['status' => 'stuck'];
    }

    /**
     * True iff every shaded cell, opened alone (the rest staying shaded),
     * changes at least one clue's burn time. A witnessed break is justified
     * by the clues themselves, never by the break count alone.
     *
     * @param  array<int, list<int>>  $adjacency
     * @param  array<int, int>  $shaded  cell index => 0|1
     * @param  array<int, int>  $clues  cell index => minute
     */
    public static function breaksWitnessed(int $cellCount, array $adjacency, int $spark, array $shaded, array $clues): bool
    {
        for ($s = 0; $s < $cellCount; $s++) {
            if (empty($shaded[$s])) {
                continue;
            }
            $d = self::bfs($cellCount, $adjacency, $spark, fn ($i) => $i === $s || empty($shaded[$i]));
            $changed = false;
            foreach ($clues as $cell => $minute) {
                if (($d[$cell] ?? -1) !== $minute) {
                    $changed = true;
                    break;
                }
            }
            if (! $changed) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    public static function shuffle(array $items, callable $rand): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = (int) floor($rand() * ($i + 1));
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    /**
     * Random terrain: a spark and $breaks shaded cells such that every
     * unshaded cell is connected to the spark.
     *
     * @return array{spark: int, shaded: array<int, int>, times: array<int, int>}
     */
    public static function randomTerrain(int $rows, int $cols, int $breaks, callable $rand): array
    {
        $n = $rows * $cols;
        $adjacency = Puzzle::buildAdjacency($rows, $cols);

        while (true) {
            $spark = (int) floor($rand() * $n);
            $pool = [];
            for ($i = 0; $i < $n; $i++) {
                if ($i !== $spark) {
                    $pool[] = $i;
                }
            }
            $pool = self::shuffle($pool, $rand);
            $shaded = array_fill(0, $n, 0);
            for ($k = 0; $k < $breaks; $k++) {
                $shaded[$pool[$k]] = 1;
            }
            $d = self::bfs($n, $adjacency, $spark, fn ($i) => empty($shaded[$i]));
            $reach = 0;
            foreach ($d as $v) {
                if ($v >= 0) {
                    $reach++;
                }
            }
            if ($reach === $n - $breaks) {
                return ['spark' => $spark, 'shaded' => $shaded, 'times' => $d];
            }
        }
    }

    /**
     * Random terrain repaired so every break is witnessed by the *full*
     * burn-time map: silent breaks (whose opening changes no burn time) are
     * relocated, connectivity preserved, until none remain.
     *
     * @return array{spark: int, shaded: array<int, int>, times: array<int, int>}
     */
    public static function witnessedTerrain(int $rows, int $cols, int $breaks, callable $rand): array
    {
        $n = $rows * $cols;
        $adjacency = Puzzle::buildAdjacency($rows, $cols);

        while (true) {
            $terrain = self::randomTerrain($rows, $cols, $breaks, $rand);
            $shaded = $terrain['shaded'];
            $spark = $terrain['spark'];
            $times = $terrain['times'];
            $ok = false;

            for ($moves = 0; $moves < 400; $moves++) {
                $silent = [];
                for ($s = 0; $s < $n; $s++) {
                    if (empty($shaded[$s])) {
                        continue;
                    }
                    $d = self::bfs($n, $adjacency, $spark, fn ($i) => $i === $s || empty($shaded[$i]));
                    $changed = false;
                    for ($i = 0; $i < $n; $i++) {
                        if (empty($shaded[$i]) && $d[$i] !== $times[$i]) {
                            $changed = true;
                            break;
                        }
                    }
                    if (! $changed) {
                        $silent[] = $s;
                    }
                }
                if (! count($silent)) {
                    $ok = true;
                    break;
                }
                $s = $silent[(int) floor($rand() * count($silent))];
                for ($tries = 0; $tries < 50; $tries++) {
                    $t = (int) floor($rand() * $n);
                    if ($t === $spark || ! empty($shaded[$t])) {
                        continue;
                    }
                    $shaded[$s] = 0;
                    $shaded[$t] = 1;
                    $d = self::bfs($n, $adjacency, $spark, fn ($i) => empty($shaded[$i]));
                    $reach = 0;
                    foreach ($d as $v) {
                        if ($v >= 0) {
                            $reach++;
                        }
                    }
                    if ($reach === $n - $breaks) {
                        $times = $d;
                        break;
                    }
                    $shaded[$s] = 1;
                    $shaded[$t] = 0;
                }
            }

            if ($ok) {
                return ['spark' => $spark, 'shaded' => $shaded, 'times' => $times];
            }
        }
    }

    /** @param  array<int, int>  $times */
    public static function hasDetour(int $rows, int $cols, int $spark, array $times): bool
    {
        $sr = intdiv($spark, $cols);
        $sc = $spark % $cols;
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < $cols; $c++) {
                $t = $times[$r * $cols + $c];
                if ($t > abs($r - $sr) + abs($c - $sc)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Generate a Burnfront incident with a unique solution, provably
     * solvable by single-cell deductions (no guessing). Builds a full
     * solution on witnessed terrain, starts from the complete clue set
     * (trivially unique), then greedily deletes clues; a deletion is kept
     * only if uniqueness, deducibility, and the witness property all still
     * hold. Time-budgeted so a request never runs away.
     *
     * @param  array{random?: callable, budgetMs?: int, minClues?: int, requireDetour?: bool}  $options
     * @return array{puzzle: Puzzle, spark: int, solution: array<int, int>, times: array<int, int>}
     */
    public static function generate(int $rows, int $cols, int $breaks, array $options = []): array
    {
        $rand = $options['random'] ?? fn () => mt_rand() / mt_getrandmax();
        $budgetMs = $options['budgetMs'] ?? 8000;
        $minClues = $options['minClues'] ?? 0;
        $requireDetour = $options['requireDetour'] ?? true;
        $t0 = microtime(true) * 1000;
        $now = fn () => microtime(true) * 1000;

        while (true) {
            $terrain = self::witnessedTerrain($rows, $cols, $breaks, $rand);
            if ($requireDetour && ! self::hasDetour($rows, $cols, $terrain['spark'], $terrain['times'])) {
                continue;
            }

            $clues = [];
            for ($i = 0; $i < $rows * $cols; $i++) {
                if (empty($terrain['shaded'][$i]) && $i !== $terrain['spark']) {
                    $clues[$i] = $terrain['times'][$i];
                }
            }
            $pz = new Puzzle($rows, $cols, $terrain['spark'], $clues, $breaks);

            $removed = true;
            while ($removed && ($now() - $t0) < $budgetMs && count($clues) > $minClues) {
                $removed = false;
                $order = self::shuffle(array_keys($clues), $rand);
                foreach ($order as $cell) {
                    if (($now() - $t0) >= $budgetMs || count($clues) <= $minClues) {
                        break;
                    }
                    if (! array_key_exists($cell, $clues)) {
                        continue; // already removed this pass
                    }
                    $trialClues = $clues;
                    unset($trialClues[$cell]);

                    if (! self::breaksWitnessed($rows * $cols, $pz->adjacency, $terrain['spark'], $terrain['shaded'], $trialClues)) {
                        continue;
                    }

                    $trial = new Puzzle($rows, $cols, $terrain['spark'], $trialClues, $breaks);
                    $res = self::countSolutions($trial, 2, 60000, $t0 + $budgetMs);
                    if (! $res['aborted'] && $res['count'] === 1 && self::deductionSolve($trial) !== null) {
                        $clues = $trialClues;
                        $pz = $trial;
                        $removed = true;
                    }
                }
            }

            $solution = [];
            for ($i = 0; $i < $rows * $cols; $i++) {
                $solution[$i] = ! empty($terrain['shaded'][$i]) ? self::SHADED : self::OPEN;
            }

            return [
                'puzzle' => $pz,
                'spark' => $terrain['spark'],
                'solution' => $solution,
                'times' => $terrain['times'],
            ];
        }
    }
}
