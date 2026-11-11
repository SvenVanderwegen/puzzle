<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\CareerProgress;
use PHPUnit\Framework\TestCase;

class CareerProgressTest extends TestCase
{
    public function test_badges_are_unearned_by_default(): void
    {
        $badges = CareerProgress::badges([
            'totalSolved' => 0,
            'bestStreak' => 0,
            'hasCleanDaily' => false,
            'hasColdCase' => false,
        ]);

        foreach ($badges as $badge) {
            $this->assertFalse($badge['earned'], "{$badge['key']} should not be earned yet");
        }
    }

    public function test_badges_earn_independently_off_their_own_fact(): void
    {
        $badges = CareerProgress::badges([
            'totalSolved' => 100,
            'bestStreak' => 7,
            'hasCleanDaily' => true,
            'hasColdCase' => true,
        ]);
        $byKey = [];
        foreach ($badges as $badge) {
            $byKey[$badge['key']] = $badge['earned'];
        }

        $this->assertTrue($byKey['first_incident']);
        $this->assertTrue($byKey['clean_reconstruction']);
        $this->assertTrue($byKey['week_streak']);
        $this->assertFalse($byKey['month_streak']); // needs a 30-day streak, not 7
        $this->assertTrue($byKey['century']);
        $this->assertTrue($byKey['cold_case']);
    }
}
