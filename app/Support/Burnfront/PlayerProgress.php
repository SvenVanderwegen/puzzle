<?php

namespace App\Support\Burnfront;

use App\Models\CampaignProfile;
use App\Models\DailyScore;
use App\Models\EndlessScore;
use App\Models\GamePlay;
use Illuminate\Support\Carbon;

/**
 * A player's progress: their points (and the rank and grade that follow
 * from them, see Progression), the weekly goal and the daily streak. Every
 * solved fire feeds all of it, whatever the mode.
 *
 * The rules live in config/burnfront.php and are also handed to the client
 * as rules(), so a guest (who has no server-side record) gets the exact
 * same numbers computed from their own device (resources/js/lib/xp.js and
 * lib/progress.js).
 *
 * Points are kept in CampaignProfile.total_xp: one number per account.
 */
final class PlayerProgress
{
    /**
     * @return array{weekGoal: int, xp: array<string, mixed>}
     */
    public static function rules(): array
    {
        return [
            'weekGoal' => max(1, (int) config('burnfront.weekly_goal', 5)),
            'xp' => Progression::rules(),
        ];
    }

    /**
     * Monday 00:00 UTC of the current week — the same UTC clock the daily
     * fire already resets on.
     */
    public static function weekStart(): Carbon
    {
        return now('UTC')->startOfWeek(Carbon::MONDAY);
    }

    /**
     * Every solve this account has on record. DailyScore and EndlessScore
     * predate the GamePlay log, so the lifetime total reads them (plus the
     * campaign's own counter) instead of counting GamePlay rows.
     */
    public static function totalSolved(int $userId): int
    {
        return DailyScore::where('user_id', $userId)->count()
            + (int) EndlessScore::where('user_id', $userId)->sum('solved_count')
            + (int) (CampaignProfile::where('user_id', $userId)->value('puzzles_solved') ?? 0);
    }

    /**
     * Solves since this week's Monday. GamePlay has one row per solved fire
     * in every mode (campaign included), which is what the week needs.
     */
    public static function weekSolved(int $userId): int
    {
        return GamePlay::where('user_id', $userId)
            ->where('created_at', '>=', self::weekStart())
            ->count();
    }

    /** This account's points. Read-only: looking never creates a profile row. */
    public static function xp(int $userId): int
    {
        return (int) (CampaignProfile::where('user_id', $userId)->value('total_xp') ?? 0);
    }

    /**
     * @return array{weekSolved: int, weekGoal: int, totalSolved: int, xp: int, level: int, intoLevel: int, levelSize: int, toGo: int, rank: int, rankKey: string, grade: int, nextRankLevel: int|null}
     */
    public static function forUser(int $userId): array
    {
        return [
            'weekSolved' => self::weekSolved($userId),
            'weekGoal' => self::rules()['weekGoal'],
            'totalSolved' => self::totalSolved($userId),
            ...Progression::standing(self::xp($userId)),
        ];
    }

    /**
     * Gives this account the points for one solved fire and reports what
     * happened, for the result screen to count up. Call it after the solve
     * has been logged (its GamePlay row, and for the daily its DailyScore),
     * because the weekly goal and the streak are read from those.
     *
     * @param  array{rows: int, cols: int, breaks?: int, daily?: bool, hintsUsed?: int|null}  $facts
     * @return array{items: list<array{key: string, xp: int, n?: int}>, total: int, xpBefore: int, xpAfter: int, level: int, leveledUp: bool, rankUp: bool}
     */
    public static function award(int $userId, array $facts): array
    {
        if ($facts['daily'] ?? false) {
            $facts['streak'] = self::streaks($userId)['current'];
        }
        // Exactly the goal: only the fire that reaches it gets the bonus.
        $facts['weekReached'] = self::weekSolved($userId) === self::rules()['weekGoal'];

        $reward = Progression::reward($facts);

        // firstOrCreate() doesn't hydrate the model from the DB's column
        // defaults after an insert, so both columns are passed explicitly.
        $profile = CampaignProfile::firstOrCreate(['user_id' => $userId], ['total_xp' => 0, 'puzzles_solved' => 0]);
        $before = (int) $profile->total_xp;
        if ($reward['total'] > 0) {
            // An atomic add: two fires finishing at once both count.
            $profile->increment('total_xp', $reward['total']);
        }
        $after = $before + $reward['total'];

        $was = Progression::standing($before);
        $now = Progression::standing($after);

        return [
            ...$reward,
            'xpBefore' => $before,
            'xpAfter' => $after,
            'level' => $now['level'],
            'leveledUp' => $now['level'] > $was['level'],
            'rankUp' => $now['rank'] > $was['rank'],
        ];
    }

    /**
     * This account's current daily streak (consecutive days up to and
     * including today or yesterday) and its longest streak ever.
     *
     * @return array{current: int, best: int}
     */
    public static function streaks(int $userId): array
    {
        $dates = DailyScore::where('user_id', $userId)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $bySet = array_flip($dates);

        $best = 0;
        $run = 0;
        $prev = null;
        foreach ($dates as $date) {
            $run = ($prev !== null && Carbon::parse($prev)->addDay()->toDateString() === $date) ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $date;
        }

        $today = now('UTC')->toDateString();
        $cursor = match (true) {
            isset($bySet[$today]) => now('UTC'),
            isset($bySet[now('UTC')->subDay()->toDateString()]) => now('UTC')->subDay(),
            default => null,
        };

        $current = 0;
        while ($cursor !== null && isset($bySet[$cursor->toDateString()])) {
            $current++;
            $cursor = $cursor->copy()->subDay();
        }

        return ['current' => $current, 'best' => $best];
    }

    /**
     * Daily fire number for the share line ("Burnfront 142"): days since
     * config('burnfront.daily_epoch'), counting the epoch itself as No. 1.
     */
    public static function dailyNumber(string $date): int
    {
        $epoch = Carbon::parse((string) config('burnfront.daily_epoch', '2026-07-03'), 'UTC')->startOfDay();
        $day = Carbon::parse($date, 'UTC')->startOfDay();

        return max(1, (int) $epoch->diffInDays($day, false) + 1);
    }
}
