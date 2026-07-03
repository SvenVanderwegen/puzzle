<?php

declare(strict_types=1);

namespace App\Domain\Solves;

/**
 * Result of the authoritative server-side BFS re-check (BurnValidator).
 * `times` follows the vector convention: row-major burn minutes over unshaded
 * cells, -1 = shaded or unreached; always populated, even for invalid shadings.
 */
final class BurnVerdict
{
    /**
     * @param  array<int, int>  $times  row-major, index r*cols+c
     */
    public function __construct(
        public readonly bool $valid,
        public readonly BurnVerdictReason $reason,
        public readonly array $times,
    ) {}
}
