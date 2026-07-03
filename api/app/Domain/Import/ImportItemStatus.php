<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Per-item result codes of POST /me/import — the frozen enum of
 * contracts/openapi.yaml #/components/schemas/ImportResult.
 *
 * Fate per code:
 * - credited:        daily stored (imported=true) + rating seed candidate +
 *                    possible streak-day contribution
 * - stats_only:      endless stored (imported=true); never rated (no board
 *                    to re-validate — RATING.md §5, brief non-goal)
 * - duplicate:       already known (client_solve_id, or this daily already
 *                    validly solved by this account) — nothing new stored
 * - invalid:         fails re-validation (shape, non-v7 id, BurnValidator)
 *                    — silently dropped
 * - board_unknown:   no published incident for the claimed date (incl.
 *                    future dates — never leaked) — dropped
 * - date_ineligible: claimed solve time is impossible (before the board was
 *                    published, or in the future) — dropped
 */
enum ImportItemStatus: string
{
    case Credited = 'credited';
    case Duplicate = 'duplicate';
    case Invalid = 'invalid';
    case BoardUnknown = 'board_unknown';
    case DateIneligible = 'date_ineligible';
    case StatsOnly = 'stats_only';
}
