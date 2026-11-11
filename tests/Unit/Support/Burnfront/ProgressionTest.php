<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\Progression;
use Tests\TestCase;

/**
 * Needs the app for config(): the rules come from config/burnfront.php.
 * resources/js/lib/xp.js mirrors standing() and reward() for the result
 * screen's count-up and for guests, so a change here must be made there too.
 */
class ProgressionTest extends TestCase
{
    public function test_levels_cost_a_little_more_each_time_up_to_the_cap(): void
    {
        $this->assertSame(60, Progression::xpToNext(1));
        $this->assertSame(80, Progression::xpToNext(2));
        $this->assertSame(440, Progression::xpToNext(20));
        $this->assertSame(500, Progression::xpToNext(23));
        $this->assertSame(500, Progression::xpToNext(200));
    }

    public function test_standing_is_the_inverse_of_xp_for_level(): void
    {
        for ($level = 1; $level <= 40; $level++) {
            $at = Progression::standing(Progression::xpForLevel($level));
            $this->assertSame($level, $at['level']);
            $this->assertSame(0, $at['intoLevel']);
            $this->assertSame(Progression::xpToNext($level), $at['toGo']);
        }

        $this->assertSame(
            ['xp' => 59, 'level' => 1, 'intoLevel' => 59, 'levelSize' => 60, 'toGo' => 1, 'rank' => 0, 'rankKey' => 'trainee', 'grade' => 1, 'nextRankLevel' => 5],
            Progression::standing(59),
        );
        // A step on the points ladder is shown as a rank and a grade inside it.
        $this->assertSame(
            ['firefighter', 2],
            [Progression::standing(Progression::xpForLevel(6))['rankKey'], Progression::standing(Progression::xpForLevel(6))['grade']],
        );
        $this->assertSame(
            ['legend', 6],
            [Progression::standing(Progression::xpForLevel(30))['rankKey'], Progression::standing(Progression::xpForLevel(30))['grade']],
        );
        $this->assertSame(1, Progression::standing(-5)['level']);
    }

    public function test_ranks_start_at_their_levels_and_the_last_has_no_next(): void
    {
        $this->assertSame('trainee', Progression::standing(Progression::xpForLevel(4))['rankKey']);
        $this->assertSame('firefighter', Progression::standing(Progression::xpForLevel(5))['rankKey']);
        $this->assertSame('captain', Progression::standing(Progression::xpForLevel(13))['rankKey']);
        $this->assertSame('commander', Progression::standing(Progression::xpForLevel(24))['rankKey']);

        $top = Progression::standing(Progression::xpForLevel(25));
        $this->assertSame('legend', $top['rankKey']);
        $this->assertNull($top['nextRankLevel']);
        $this->assertSame('legend', Progression::standing(Progression::xpForLevel(80))['rankKey']);
    }

    public function test_bigger_boards_give_more_points(): void
    {
        $this->assertSame(20, Progression::fireXp(5, 5));
        $this->assertSame(30, Progression::fireXp(6, 6));
        $this->assertSame(50, Progression::fireXp(8, 8));
        $this->assertSame(10, Progression::fireXp(4, 4));
        $this->assertSame(70, Progression::fireXp(10, 10));
        $this->assertSame(40, Progression::fireXp(5, 7));
    }

    public function test_a_reward_lists_each_line_and_adds_them_up(): void
    {
        $reward = Progression::reward([
            'rows' => 6, 'cols' => 6, 'breaks' => 8,
            'daily' => true, 'hintsUsed' => 0, 'streak' => 3, 'weekReached' => true,
        ]);

        $this->assertSame([
            ['key' => 'fire', 'xp' => 30],
            ['key' => 'noHints', 'xp' => 10],
            ['key' => 'daily', 'xp' => 20],
            ['key' => 'streak', 'xp' => 15, 'n' => 3],
            ['key' => 'week', 'xp' => 50],
        ], $reward['items']);
        $this->assertSame(125, $reward['total']);
    }

    public function test_the_streak_bonus_starts_on_day_two_and_stops_growing(): void
    {
        $streak = fn (int $days) => collect(Progression::reward(['rows' => 5, 'cols' => 5, 'streak' => $days])['items'])
            ->firstWhere('key', 'streak')['xp'] ?? 0;

        $this->assertSame(0, $streak(0));
        $this->assertSame(0, $streak(1));
        $this->assertSame(10, $streak(2));
        $this->assertSame(25, $streak(5));
        $this->assertSame(25, $streak(40));
    }

    /**
     * Hints never cost points, with one exception: a board where the hints
     * placed every firebreak gives nothing for the fire.
     */
    public function test_hints_only_matter_when_they_solved_the_whole_board(): void
    {
        $with = fn (?int $hints) => Progression::reward(['rows' => 6, 'cols' => 6, 'breaks' => 6, 'hintsUsed' => $hints]);

        $this->assertSame(40, $with(0)['total']); // fire + no hints
        $this->assertSame(30, $with(1)['total']);
        $this->assertSame(30, $with(5)['total']);
        $this->assertSame(0, $with(6)['total']);
        $this->assertSame(30, $with(null)['total']); // hints not counted (practice)
    }

    public function test_the_rules_follow_config(): void
    {
        config(['burnfront.xp.level_base' => 100, 'burnfront.xp.level_step' => 0, 'burnfront.xp.daily' => 0]);

        $this->assertSame(3, Progression::standing(250)['level']);
        $this->assertSame([['key' => 'fire', 'xp' => 30]], Progression::reward(['rows' => 6, 'cols' => 6, 'daily' => true])['items']);
    }
}
