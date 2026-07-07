<?php

namespace App\Support\Burnfront;

/**
 * A small deterministic PRNG (xorshift32) usable as Engine::generate()'s
 * `random` callable. Independent of PHP's global RNG state, so the same
 * seed always reproduces the same incident — the hook PuzzleService::
 * generateDaily() seeds from the date (and a secret; see its docblock).
 */
final class SeededRandom
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & 0x7FFFFFFF;
        if ($this->state === 0) {
            $this->state = 1;
        }
    }

    /** Returns a float in [0, 1). */
    public function __invoke(): float
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return ($this->state & 0x7FFFFFFF) / 0x80000000;
    }
}
