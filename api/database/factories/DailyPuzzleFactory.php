<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Daily calendar rows for feature tests. Pass an explicit date; the incident
 * number is sequential per test run.
 *
 * @extends Factory<DailyPuzzle>
 */
class DailyPuzzleFactory extends Factory
{
    private static int $sequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'date' => now('UTC')->format('Y-m-d'),
            'puzzle_id' => Puzzle::factory(),
            'incident_number' => ++self::$sequence,
            'published_at' => now(),
            'calendar_version' => 'v20260701-1',
            'amnesty' => false,
        ];
    }
}
