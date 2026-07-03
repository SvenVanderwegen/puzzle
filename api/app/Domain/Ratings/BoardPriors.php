<?php

declare(strict_types=1);

namespace App\Domain\Ratings;

use App\Models\Puzzle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * RATING.md §2 board priors and the §4 endless derivation.
 *
 * Stored boards: rating = base(tier) + 4 x grade_score, RD 200, seeded on the
 * first rated solve and never re-fit (ADR-0010) — Glicko-2 self-calibrates
 * from there.
 *
 * Endless boards have no puzzles row: grade_score = the client-reported
 * deduction_steps clamped to a tier's observed grade_score range in the
 * puzzles table (bounds resolved at runtime). The tier is the easiest tier
 * whose observed range still reaches the claimed steps — conservative, so a
 * fabricated step count buys the cheapest plausible board. Tiers with fewer
 * than MIN_TIER_SAMPLE imported puzzles fall back to constants drawn from the
 * pipeline distribution in contracts/vectors/generate.v1.jsonl
 * (deduction_steps 6-38 across 3x3..7x7 boards).
 */
final class BoardPriors
{
    public const IMPORT_RD = 200.0;

    public const MIN_TIER_SAMPLE = 10;

    private const TIERS = ['lookout', 'crew', 'hotshot'];

    /**
     * Documented fallbacks for sparse tiers: [min, max] grade_score.
     *
     * @var array{lookout: array{float, float}, crew: array{float, float}, hotshot: array{float, float}}
     */
    private const FALLBACK_BOUNDS = [
        'lookout' => [3.0, 10.0],
        'crew' => [10.0, 22.0],
        'hotshot' => [22.0, 40.0],
    ];

    public function priorForPuzzle(Puzzle $puzzle): float
    {
        return self::base($puzzle->grade_tier) + 4.0 * (float) $puzzle->grade_score;
    }

    public function priorForEndless(int $deductionSteps): float
    {
        $bounds = $this->tierBounds();

        $tier = 'hotshot';

        foreach (self::TIERS as $candidate) {
            if ((float) $deductionSteps <= $bounds[$candidate][1]) {
                $tier = $candidate;
                break;
            }
        }

        $gradeScore = min(max((float) $deductionSteps, $bounds[$tier][0]), $bounds[$tier][1]);

        return self::base($tier) + 4.0 * $gradeScore;
    }

    /**
     * base(lookout) = 1000, base(crew) = 1300, base(hotshot) = 1550
     * (RATING.md §2, frozen).
     */
    public static function base(string $tier): float
    {
        return match ($tier) {
            'lookout' => 1000.0,
            'crew' => 1300.0,
            'hotshot' => 1550.0,
            default => throw new InvalidArgumentException("Unknown grade tier: {$tier}."),
        };
    }

    /**
     * Observed grade_score range per tier, falling back per-tier when the
     * imported corpus is too sparse to trust.
     *
     * @return array{lookout: array{float, float}, crew: array{float, float}, hotshot: array{float, float}}
     */
    private function tierBounds(): array
    {
        $observed = DB::table('puzzles')
            ->selectRaw('grade_tier, min(grade_score) as lo, max(grade_score) as hi, count(*) as n')
            ->groupBy('grade_tier')
            ->get()
            ->keyBy('grade_tier');

        $bounds = self::FALLBACK_BOUNDS;

        foreach (self::TIERS as $tier) {
            /** @var object{lo: mixed, hi: mixed, n: mixed}|null $row */
            $row = $observed->get($tier);

            if ($row === null || ! is_numeric($row->n) || (int) $row->n < self::MIN_TIER_SAMPLE) {
                continue;
            }

            if (is_numeric($row->lo) && is_numeric($row->hi)) {
                $bounds[$tier] = [(float) $row->lo, (float) $row->hi];
            }
        }

        return $bounds;
    }
}
