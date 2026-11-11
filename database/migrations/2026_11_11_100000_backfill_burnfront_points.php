<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Points used to come from campaign fires only. They now come from every
 * solved fire, so this gives each account the points for the daily and
 * practice fires it solved before the change: the plain "fire" points for
 * the board's size plus the "today's fire" bonus for a daily, with no
 * streak or no-hints extras. Campaign fires already paid out when they were
 * solved, so they are left alone. Points are only ever added.
 */
return new class extends Migration
{
    public function up(): void
    {
        $xp = (array) config('burnfront.xp', []);
        $base = (int) ($xp['fire_base'] ?? 20);
        $perSide = (int) ($xp['fire_per_side'] ?? 10);
        $min = (int) ($xp['fire_min'] ?? 10);
        $max = (int) ($xp['fire_max'] ?? 70);
        $daily = (int) ($xp['daily'] ?? 20);

        $owed = [];
        $groups = DB::table('burnfront_game_plays')
            ->whereIn('mode', ['daily', 'endless'])
            ->select('user_id', 'mode', 'rows', 'cols', DB::raw('count(*) as solved'))
            ->groupBy('user_id', 'mode', 'rows', 'cols')
            ->get();

        foreach ($groups as $row) {
            $side = max((int) $row->rows, (int) $row->cols);
            $each = max($min, min($max, $base + $perSide * ($side - 5)));
            if ($row->mode === 'daily') {
                $each += $daily;
            }
            $owed[$row->user_id] = ($owed[$row->user_id] ?? 0) + $each * (int) $row->solved;
        }

        $now = now();
        foreach ($owed as $userId => $points) {
            $exists = DB::table('burnfront_campaign_profiles')->where('user_id', $userId)->exists();
            if ($exists) {
                DB::table('burnfront_campaign_profiles')->where('user_id', $userId)->increment('total_xp', $points);
            } else {
                DB::table('burnfront_campaign_profiles')->insert([
                    'user_id' => $userId,
                    'total_xp' => $points,
                    'puzzles_solved' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Not reversible: once added, backfilled points cannot be told apart
     * from points earned afterwards.
     */
    public function down(): void {}
};
