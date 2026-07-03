<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Streak rollover right after UTC midnight (ADR-0002; app timezone is UTC):
// consume freezes / reset lapsed streaks, emit failed-daily rating hooks.
Schedule::command('streaks:rollover')->dailyAt('00:05');

// GDPR retention windows (docs/gdpr.md; WS-06 brief). All cutoffs are rolling.
Schedule::command('retention:purge-solve-artifacts')->dailyAt('03:10');
Schedule::command('retention:purge-frontend-errors')->dailyAt('03:20');
Schedule::command('retention:purge-events')->dailyAt('03:30');
