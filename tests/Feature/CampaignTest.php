<?php

namespace Tests\Feature;

use App\Models\CampaignProfile;
use App\Models\User;
use App\Support\Burnfront\CampaignService;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_routes_require_authentication(): void
    {
        $this->get('/campaign')->assertRedirect('/login');
        $this->get('/campaign/play')->assertRedirect('/login');
        // JSON requests get a 401, not a redirect — standard Laravel auth
        // middleware behavior for requests that expect a JSON response.
        $this->getJson('/campaign/puzzle')->assertStatus(401);
        $this->postJson('/campaign/score', [])->assertStatus(401);
    }

    public function test_map_screen_starts_a_fresh_account_at_level_one(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/campaign');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/CampaignMap')
            ->where('progress.level', 1)
            ->where('progress.totalXp', 0)
            ->where('chapters.0.levels.0.state', 'current')
            ->where('chapters.0.levels.1.state', 'locked')
        );
    }

    public function test_play_screen_renders_the_current_levels_config(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/campaign/play');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('mode', 'campaign')
            ->where('levelConfig.rows', 5)
            ->where('levelConfig.cols', 5)
            ->where('levelConfig.breaks', 3)
        );
    }

    public function test_puzzle_endpoint_generates_a_board_at_the_accounts_current_level(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/campaign/puzzle');

        $response->assertStatus(200);
        $response->assertJson(['difficulty' => 'campaign', 'rows' => 5, 'cols' => 5, 'breaks' => 3]);
    }

    public function test_puzzle_endpoint_reflects_a_higher_level_once_earned(): void
    {
        $user = User::factory()->create();
        CampaignProfile::create([
            'user_id' => $user->id,
            'total_xp' => CampaignService::cumulativeXpForLevel(5),
            'puzzles_solved' => 4,
        ]);

        $response = $this->actingAs($user)->getJson('/campaign/puzzle');

        $response->assertJson(['rows' => 6, 'cols' => 6, 'breaks' => 6]);
    }

    public function test_submit_score_awards_xp_for_a_clean_solve(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->generateLevel(1);
        $shaded = $this->solveLevel($puzzle);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => $shaded,
            'hints_used' => 0,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'xpAwarded' => CampaignService::baseXp(1),
            'level' => 1,
            'leveledUp' => false,
        ]);

        $profile = CampaignProfile::where('user_id', $user->id)->first();
        $this->assertSame(CampaignService::baseXp(1), $profile->total_xp);
        $this->assertSame(1, $profile->puzzles_solved);
    }

    public function test_submit_score_zeroes_xp_once_hints_reach_the_break_count(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->generateLevel(1);
        $shaded = $this->solveLevel($puzzle);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => $shaded,
            'hints_used' => $puzzle['breaks'],
        ]);

        $response->assertJson(['xpAwarded' => 0]);
        $this->assertSame(0, CampaignProfile::where('user_id', $user->id)->value('total_xp'));
    }

    public function test_submit_score_rejects_an_incorrect_board(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->generateLevel(1);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => [],
            'hints_used' => 0,
        ]);

        $response->assertStatus(422);
        // A profile row may already exist (submitScore() reads it to know
        // which level to score against before validating the board), but
        // a rejected board must never grant XP or count as a solve.
        $profile = CampaignProfile::where('user_id', $user->id)->first();
        $this->assertTrue($profile === null || ($profile->total_xp === 0 && $profile->puzzles_solved === 0));
    }

    public function test_submit_score_ignores_a_client_supplied_level_and_scores_against_the_earned_one(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->generateLevel(1);
        $shaded = $this->solveLevel($puzzle);

        // This account has never earned past level 1, so even a request
        // that claims to be level 16 must be scored against level 1's XP
        // award — the controller never reads a "level" field from the
        // request body at all, only this account's own CampaignProfile.
        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'level' => 16,
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => $shaded,
            'hints_used' => 0,
        ]);

        $response->assertJson(['xpAwarded' => CampaignService::baseXp(1)]);
        $this->assertSame(CampaignService::baseXp(1), CampaignProfile::where('user_id', $user->id)->value('total_xp'));
    }

    public function test_leveling_up_crosses_into_the_next_level_and_updates_chapter(): void
    {
        $user = User::factory()->create();
        CampaignProfile::create([
            'user_id' => $user->id,
            'total_xp' => CampaignService::cumulativeXpForLevel(2) - CampaignService::baseXp(1),
            'puzzles_solved' => 3,
        ]);

        $puzzle = $this->generateLevel(1);
        $shaded = $this->solveLevel($puzzle);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => $shaded,
            'hints_used' => 0,
        ]);

        $response->assertJson(['level' => 2, 'leveledUp' => true, 'chapterLabel' => 'Lookout']);
    }

    public function test_hint_endpoint_accepts_the_campaign_difficulty(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->generateLevel(1);

        $response = $this->actingAs($user)->getJson('/hint?'.http_build_query([
            'difficulty' => 'campaign',
            'spark' => $puzzle['spark'],
            'clues' => json_encode($puzzle['clues']),
            'shaded' => json_encode([]),
            'open' => json_encode([]),
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure(['status']);
    }

    public function test_hint_endpoint_rejects_the_campaign_difficulty_for_a_guest(): void
    {
        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'campaign',
            'spark' => 0,
            'clues' => json_encode([]),
        ]));

        $response->assertStatus(422);
    }

    public function test_solve_endpoint_returns_the_full_solution_for_the_campaign_difficulty(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->generateLevel(1);

        $response = $this->actingAs($user)->getJson('/solve?'.http_build_query([
            'difficulty' => 'campaign',
            'spark' => $puzzle['spark'],
            'clues' => json_encode($puzzle['clues']),
        ]));

        $response->assertStatus(200);
        $this->assertCount($puzzle['breaks'], $response->json('solution'));
    }

    /** @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>} */
    private function generateLevel(int $level): array
    {
        return app(PuzzleService::class)->generateCampaign(CampaignService::levelConfig($level));
    }

    /** @return list<int> the shaded cells of a valid solution for a generateLevel()-shaped payload */
    private function solveLevel(array $puzzle): array
    {
        $clues = [];
        foreach ($puzzle['clues'] as [$cell, $minute]) {
            $clues[$cell] = $minute;
        }
        $p = new Puzzle($puzzle['rows'], $puzzle['cols'], $puzzle['spark'], $clues, $puzzle['breaks']);

        $state = Engine::deductionSolve($p);
        $this->assertNotNull($state, 'campaign incident should be solvable by pure deduction');

        $shaded = [];
        foreach ($state as $cell => $value) {
            if ($value === Engine::SHADED) {
                $shaded[] = $cell;
            }
        }

        return $shaded;
    }
}
