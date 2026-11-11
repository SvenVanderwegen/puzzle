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

    // Points ("XP"). Every solved fire gives points, points raise the level,
    // and every few levels the player moves up a rank. See
    // App\Support\Burnfront\Progression. All of these are proposals.
    'xp' => [
        // Going from level L to L+1 takes level_base + (L - 1) * level_step
        // points (60, 80, 100, ...), never more than level_cap.
        'level_base' => 60,
        'level_step' => 20,
        'level_cap' => 500,

        // The level each rank starts at: Trainee, Firefighter, Crew leader,
        // Captain, Chief, Commander, Legend. Ranks 1 to 5 line up with the
        // campaign's five board types (four levels each).
        'rank_levels' => [1, 5, 9, 13, 17, 21, 25],

        // Points for the fire itself: fire_base for a 5 by 5, fire_per_side
        // more for each extra row (20, 30, 40, 50 for 5x5 to 8x8).
        'fire_base' => 20,
        'fire_per_side' => 10,
        'fire_min' => 10,
        'fire_max' => 70,

        // Extra points. Nothing is ever taken away.
        'no_hints' => 10,        // daily and campaign, solved without a hint
        'daily' => 20,           // today's fire
        'streak_per_day' => 5,   // from day 2 in a row: 10, 15, 20, 25
        'streak_max_days' => 5,
        'week_goal' => 50,       // once, on the fire that reaches the weekly goal
    ],

    // The date of daily fire No. 1, for the "Burnfront 142" share line.
    'daily_epoch' => env('BURNFRONT_DAILY_EPOCH', '2026-07-03'),

    // Which endless tiers the home screen offers as one-tap quick fires.
    'quick_fires' => ['lookout', 'crew', 'division'],

    // What a guest (no account, so no campaign) is offered as "Up next".
    'guest_next_tier' => 'lookout',
];
