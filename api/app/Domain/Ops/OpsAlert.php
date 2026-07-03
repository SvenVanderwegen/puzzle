<?php

declare(strict_types=1);

namespace App\Domain\Ops;

use RuntimeException;

/**
 * An operational alert (WS-18). The ops:* scheduled checks raise one when a
 * condition needs a human. Every raise reaches three sinks at once:
 *
 *  1. the `ops` log channel (config/logging.php) — laravel.log today; wiring
 *     a real notifier later is one env change (OPS_LOG_CHANNELS=stack,slack);
 *  2. report() — once the owner sets NIGHTWATCH_TOKEN, every OpsAlert shows
 *     up in Nightwatch as an exception grouped by this class, alertable from
 *     the Nightwatch UI with zero code changes;
 *  3. the raising command exits non-zero, so the scheduler marks the run
 *     failed — Nightwatch scheduled-task monitoring and any cron monitor see
 *     the same incident.
 *
 * Alert definitions the owner clicks into the Nightwatch UI are listed in
 * docs/RUNBOOK.md §6 (monitoring).
 */
final class OpsAlert extends RuntimeException {}
