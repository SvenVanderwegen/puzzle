<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Solve;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Minimal valid endless-mode solves (no puzzles fixture needed) for the WS-06
 * GDPR and retention tests. The real write path is WS-07.
 *
 * @extends Factory<Solve>
 */
class SolveFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'puzzle_id' => null,
            'mode' => 'endless',
            'client_solve_id' => (string) Str::uuid(),
            'shaded_bits' => '011010',
            'client_ms' => 42000,
            'official_ms' => 42000,
            'received_at' => now(),
            'valid' => true,
            'suspect' => false,
            'imported' => false,
            'hints_s1' => 0,
            'hints_s2' => 0,
            'hints_s3' => 0,
            'undo_count' => 0,
            'endless_spec' => [
                'rows' => 3,
                'cols' => 3,
                'spark' => [0, 0],
                'breaks' => 1,
                'clues' => [['r' => 2, 'c' => 2, 'm' => 4]],
            ],
        ];
    }

    public function withArtifacts(): static
    {
        return $this->state(fn (array $attributes): array => [
            // PG hex-format bytea literal: binary with NUL bytes cannot cross the text protocol.
            'replay' => '\\x'.bin2hex(gzencode('[[0,4,1]]') ?: ''),
            'replay_sha256' => hash('sha256', '[[0,4,1]]'),
            'ip_hash' => hash('sha256', 'ip-fixture'),
            'ua_hash' => hash('sha256', 'ua-fixture'),
        ]);
    }
}
