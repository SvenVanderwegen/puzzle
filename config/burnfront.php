<?php

/*
 * Tunables for the progression loop around the daily fire (weekly goal,
 * player level, daily number). These are proposals, not settled design:
 * keep them here so they can change without touching code.
 */
return [

    // Fires a player is asked to solve per week (Monday to Sunday, UTC).
    // Every solved fire counts: daily, campaign and quick fire alike.
    'weekly_goal' => (int) env('BURNFRONT_WEEKLY_GOAL', 5),

    // Player level, fed by every solved fire. Going from level L to L+1
    // takes level_base + (L - 1) * level_step solves: 3, 4, 5, ... with the
    // defaults, so level 4 is reached after 12 solves.
    'level_base' => (int) env('BURNFRONT_LEVEL_BASE', 3),
    'level_step' => (int) env('BURNFRONT_LEVEL_STEP', 1),

    // The date of daily fire No. 1, for the "Burnfront 142" share line.
    'daily_epoch' => env('BURNFRONT_DAILY_EPOCH', '2026-07-03'),

    // Which endless tiers the home screen offers as one-tap quick fires.
    'quick_fires' => ['lookout', 'crew', 'division'],

    // What a guest (no account, so no campaign) is offered as "Up next".
    'guest_next_tier' => 'lookout',
];
