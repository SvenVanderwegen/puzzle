<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\PlayerProgress;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Needs the app for config(): the rules come from config/burnfront.php.
 * The points, level and rank maths is covered in ProgressionTest.
 */
class PlayerProgressTest extends TestCase
{
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
