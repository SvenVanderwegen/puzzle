<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Landing social proof (product.md §2.5)
    |--------------------------------------------------------------------------
    |
    | WS-15 shipped the counter behind this flag; WS-19 flips it on now that
    | the analytics intake exists. With the flag on, the landing shows the
    | live daily solve count once today's daily_stats reach 500 solves and
    | the rank fallback below that; off forces the rank fallback everywhere.
    |
    */

    'live_counter' => (bool) env('LANDING_LIVE_COUNTER', true),

];
