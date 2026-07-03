<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// GDPR retention windows (docs/gdpr.md; WS-06 brief). All cutoffs are rolling.
Schedule::command('retention:purge-solve-artifacts')->dailyAt('03:10');
Schedule::command('retention:purge-frontend-errors')->dailyAt('03:20');
Schedule::command('retention:purge-events')->dailyAt('03:30');
