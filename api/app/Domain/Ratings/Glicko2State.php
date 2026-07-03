<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

/**
 * Immutable (rating, RD, volatility) triple on the Glicko (display) scale.
 */
final readonly class Glicko2State
{
    public function __construct(
        public float $rating,
        public float $rd,
        public float $volatility,
    ) {}

    /**
     * RATING.md §2: board ratings are capped at RD >= 50 (self-calibration
     * never becomes overconfident).
     */
    public function withRdFloor(float $floor): self
    {
        return $this->rd >= $floor ? $this : new self($this->rating, $floor, $this->volatility);
    }
}
