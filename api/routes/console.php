<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Streak rollover right after UTC midnight (ADR-0002; app timezone is UTC):
// consume freezes / reset lapsed streaks, emit failed-daily rating hooks.
Schedule::command('streaks:rollover')->dailyAt('00:05');

// GDPR retention windows (docs/gdpr.md; WS-06/WS-19 briefs). All cutoffs are
// rolling. analytics:purge covers both frontend_errors (90d) and events
// (13mo aggregate-then-purge); the narrower retention:purge-* commands remain
// for manual runs but are not scheduled twice.
Schedule::command('retention:purge-solve-artifacts')->dailyAt('03:10');
Schedule::command('analytics:purge')->dailyAt('03:20');

// Weekly owner digest (ADR-0008), Mondays after the UTC day settles.
Schedule::command('analytics:digest')->weeklyOn(1, '06:10');

// Streak-protection alerts (WS-21): hourly sweep. Each user is matched in the
// hour their LOCAL clock (users.timezone) reads the configured evening hour;
// IANA offsets are 15-minute multiples, so every zone's 60-minute window
// contains exactly one hourly tick per local day. The deadline the mail warns
// about stays UTC midnight (ADR-0002).
Schedule::command('notifications:streak-risk')->hourlyAt(15);

// Content ops checks (WS-18). Freshness runs at 22:00 UTC — T-2h before
// tomorrow's daily goes live at 00:00 UTC (critique #17). Runway runs in the
// owner's daytime; it asks for new content, not for a midnight scramble.
// Both alert by failing: ops log channel + report(OpsAlert) + non-zero exit,
// so Nightwatch scheduled-task monitoring and any cron monitor see the miss.
Schedule::command('ops:content-freshness')->dailyAt('22:00');
Schedule::command('ops:content-runway')->dailyAt('08:30');
