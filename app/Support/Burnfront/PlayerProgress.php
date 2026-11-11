<?php

namespace App\Support\Burnfront;

use App\Models\CampaignProfile;
use App\Models\DailyScore;
use App\Models\EndlessScore;
use App\Models\GamePlay;
use Illuminate\Support\Carbon;

/**
 * The progression loop that sits beside the daily fire, so a player has a
 * reason to play more than one fire a day: a weekly goal (N fires per week)
 * and a player level. Both are fed by every solved fire, whatever the mode.
 *
 * The rules (goal size, level curve) live in config/burnfront.php and are
 * also handed to the client as rules(), so a guest — who has no server-side
 * record — gets the exact same numbers computed from their local solve log
 * (resources/js/lib/progress.js mirrors levelForSolves()).
 */
final class PlayerProgress
{
    /**
     * @return array{weekGoal: int, levelBase: int, levelStep: int}
     */
    public static function rules(): array
    {
        return [
            'weekGoal' => max(1, (int) config('burnfront.weekly_goal', 5)),
            'levelBase' => max(1, (int) config('burnfront.level_base', 3)),
            'levelStep' => max(0, (int) config('burnfront.level_step', 1)),
        ];
    }

    /**
     * Level from a total solve count. Going from level L to L+1 costs
     * levelBase + (L - 1) * levelStep solves.
     *
     * @return array{level: int, intoLevel: int, levelSize: int, toGo: int}
     */
    public static function levelForSolves(int $totalSolved): array
    {
        $rules = self::rules();
        $level = 1;
        $remaining = max(0, $totalSolved);
        $size = $rules['levelBase'];

        while ($remaining >= $size) {
            $remaining -= $size;
            $level++;
            $size = $rules['levelBase'] + ($level - 1) * $rules['levelStep'];
        }

        return [
            'level' => $level,
            'intoLevel' => $remaining,
            'levelSize' => $size,
            'toGo' => $size - $remaining,
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

    /**
     * @return array{weekSolved: int, weekGoal: int, totalSolved: int, level: int, intoLevel: int, levelSize: int, toGo: int}
     */
    public static function forUser(int $userId): array
    {
        $total = self::totalSolved($userId);

        return [
            'weekSolved' => self::weekSolved($userId),
            'weekGoal' => self::rules()['weekGoal'],
            'totalSolved' => $total,
            ...self::levelForSolves($total),
        ];
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
