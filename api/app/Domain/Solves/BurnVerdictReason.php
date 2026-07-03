<?php

declare(strict_types=1);

namespace App\Domain\Solves;

/**
 * BurnVerdictReason — FROZEN check order per contracts/vectors/README.md:
 * spark_shaded -> clue_shaded (row-major first) -> wrong_break_count ->
 * unreachable_cell (row-major first) -> clue_time_mismatch (row-major first)
 * -> ok. Mirrored by SolveResult.reason in contracts/openapi.yaml.
 */
enum BurnVerdictReason: string
{
    case Ok = 'ok';
    case SparkShaded = 'spark_shaded';
    case ClueShaded = 'clue_shaded';
    case WrongBreakCount = 'wrong_break_count';
    case UnreachableCell = 'unreachable_cell';
    case ClueTimeMismatch = 'clue_time_mismatch';
}
