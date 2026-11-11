<?php

namespace Tests\Feature;

use App\Models\CampaignProfile;
use App\Models\GamePlay;
use App\Models\User;
use App\Support\Burnfront\PlayerProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-off migration that gives accounts the points for the daily and
 * practice fires they solved before every mode gave points.
 */
class PointsBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_past_daily_and_practice_fires_are_paid_out_and_campaign_fires_are_left_alone(): void
    {
        $withProfile = User::factory()->create();
        $withoutProfile = User::factory()->create();
        $untouched = User::factory()->create();
        CampaignProfile::create(['user_id' => $withProfile->id, 'total_xp' => 100, 'puzzles_solved' => 4]);

        $this->play($withProfile, 'daily', 6);     // 30 + 20
        $this->play($withProfile, 'endless', 8);   // 50
        $this->play($withProfile, 'campaign', 5);  // already paid
        $this->play($withoutProfile, 'endless', 5); // 20
        $this->play($withoutProfile, 'endless', 5); // 20

        $migration = require database_path('migrations/2026_11_11_100000_backfill_burnfront_points.php');
        $migration->up();

        $this->assertSame(200, PlayerProgress::xp($withProfile->id));
        $this->assertSame(4, CampaignProfile::where('user_id', $withProfile->id)->value('puzzles_solved'));
        $this->assertSame(40, PlayerProgress::xp($withoutProfile->id));
        $this->assertSame(0, CampaignProfile::where('user_id', $untouched->id)->count());
    }

    private function play(User $user, string $mode, int $side): void
    {
        GamePlay::create([
            'user_id' => $user->id,
            'mode' => $mode,
            'difficulty' => null,
            'date' => $mode === 'daily' ? now('UTC')->toDateString() : null,
            'rows' => $side,
            'cols' => $side,
            'breaks' => 3,
            'spark' => 0,
            'clues' => [],
            'shaded_cells' => [],
            'moves' => [],
            'time_ms' => null,
            'hints_used' => 0,
        ]);
    }
}
