<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\PlayerProgress;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Needs the app for config(): the rules come from config/burnfront.php.
 * resources/js/lib/progress.js mirrors levelForSolves() for guests, so a
 * change to the curve here must be made there too.
 */
class PlayerProgressTest extends TestCase
{
    public function test_level_curve_with_the_default_rules(): void
    {
        $this->assertSame(['level' => 1, 'intoLevel' => 0, 'levelSize' => 3, 'toGo' => 3], PlayerProgress::levelForSolves(0));
        $this->assertSame(['level' => 1, 'intoLevel' => 2, 'levelSize' => 3, 'toGo' => 1], PlayerProgress::levelForSolves(2));
        $this->assertSame(['level' => 2, 'intoLevel' => 0, 'levelSize' => 4, 'toGo' => 4], PlayerProgress::levelForSolves(3));
        $this->assertSame(['level' => 3, 'intoLevel' => 0, 'levelSize' => 5, 'toGo' => 5], PlayerProgress::levelForSolves(7));
        $this->assertSame(['level' => 4, 'intoLevel' => 2, 'levelSize' => 6, 'toGo' => 4], PlayerProgress::levelForSolves(14));
    }

    public function test_level_curve_follows_config(): void
    {
        config(['burnfront.level_base' => 5, 'burnfront.level_step' => 0]);

        $this->assertSame(3, PlayerProgress::levelForSolves(10)['level']);
        $this->assertSame(5, PlayerProgress::levelForSolves(10)['levelSize']);
    }

    public function test_week_starts_on_monday_utc(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-15 23:30:00', 'UTC')); // a Sunday
        $this->assertSame('2026-11-09 00:00:00', PlayerProgress::weekStart()->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-11-16 00:10:00', 'UTC')); // the Monday after
        $this->assertSame('2026-11-16 00:00:00', PlayerProgress::weekStart()->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_daily_number_counts_the_epoch_as_one(): void
    {
        config(['burnfront.daily_epoch' => '2026-07-03']);

        $this->assertSame(1, PlayerProgress::dailyNumber('2026-07-03'));
        $this->assertSame(2, PlayerProgress::dailyNumber('2026-07-04'));
        $this->assertSame(131, PlayerProgress::dailyNumber('2026-11-10'));
    }
}
