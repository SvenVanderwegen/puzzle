<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\CareerProgress;
use PHPUnit\Framework\TestCase;

class CareerProgressTest extends TestCase
{
    public function test_rank_starts_at_trainee_with_a_next_threshold(): void
    {
        $rank = CareerProgress::rank(0);

        $this->assertSame('Trainee Analyst', $rank['title']);
        $this->assertSame(0, $rank['totalSolved']);
        $this->assertSame('Field Analyst', $rank['nextTitle']);
        $this->assertSame(5, $rank['nextThreshold']);
    }

    public function test_rank_advances_at_each_threshold_boundary(): void
    {
        $this->assertSame('Trainee Analyst', CareerProgress::rank(4)['title']);
        $this->assertSame('Field Analyst', CareerProgress::rank(5)['title']);
        $this->assertSame('Senior Analyst', CareerProgress::rank(20)['title']);
        $this->assertSame('Lead Investigator', CareerProgress::rank(50)['title']);
        $this->assertSame('Chief Investigator', CareerProgress::rank(100)['title']);
        $this->assertSame('Bureau Chief', CareerProgress::rank(250)['title']);
    }

    public function test_rank_has_no_next_title_at_the_top_of_the_ladder(): void
    {
        $rank = CareerProgress::rank(1000);

        $this->assertSame('Bureau Chief', $rank['title']);
        $this->assertNull($rank['nextTitle']);
        $this->assertNull($rank['nextThreshold']);
    }

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
