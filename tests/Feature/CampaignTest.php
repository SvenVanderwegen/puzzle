<?php

namespace Tests\Feature;

use App\Models\CampaignProfile;
use App\Models\GamePlay;
use App\Models\User;
use App\Support\Burnfront\CampaignService;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Progression;
use App\Support\Burnfront\Puzzle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
            ->where('standing.level', 1)
            ->where('standing.xp', 0)
            ->where('standing.rankKey', 'trainee')
            ->has('ranks', 7)
            ->where('ranks.0.state', 'now')
            ->where('ranks.0.levels.0.state', 'current')
            ->where('ranks.0.levels.1.state', 'locked')
            ->where('ranks.1.state', 'later')
            ->where('ranks.6.levels', []) // the last rank has no end
            ->where('next.kind', 'campaign')
        );
    }

    public function test_play_screen_renders_the_current_levels_config(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/campaign/play');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('mode', 'campaign')
            ->where('levelConfig.level', 1)
            ->where('levelConfig.rows', 5)
            ->where('levelConfig.cols', 5)
            ->where('levelConfig.breaks', 3)
        );
    }

    public function test_puzzle_endpoint_generates_a_board_at_the_accounts_current_level_and_issues_a_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/campaign/puzzle');

        $response->assertStatus(200);
        $response->assertJson(['difficulty' => 'campaign', 'rows' => 5, 'cols' => 5, 'breaks' => 3]);
        $response->assertJsonStructure(['token']);
        $this->assertIsString($response->json('token'));
        $this->assertNotSame('', $response->json('token'));
    }

    public function test_puzzle_endpoint_reflects_a_higher_level_once_earned(): void
    {
        $user = User::factory()->create();
        CampaignProfile::create([
            'user_id' => $user->id,
            'total_xp' => Progression::xpForLevel(5),
            'puzzles_solved' => 4,
        ]);

        $response = $this->actingAs($user)->getJson('/campaign/puzzle');

        $response->assertJson(['rows' => 6, 'cols' => 6, 'breaks' => 6]);
    }

    public function test_submit_score_awards_xp_for_a_clean_solve(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user);
        $shaded = $this->solveLevel($puzzle);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'token' => $puzzle['token'],
            'shaded' => $shaded,
        ]);

        $response->assertStatus(200);
        // A 5 by 5 fire (20) solved without a hint (10).
        $response->assertJson([
            'xpAwarded' => 30,
            'level' => 1,
            'leveledUp' => false,
            'reward' => [
                'items' => [['key' => 'fire', 'xp' => 20], ['key' => 'noHints', 'xp' => 10]],
                'total' => 30,
                'xpBefore' => 0,
                'xpAfter' => 30,
                'rankUp' => false,
            ],
        ]);

        $profile = CampaignProfile::where('user_id', $user->id)->first();
        $this->assertSame(30, $profile->total_xp);
        $this->assertSame(1, $profile->puzzles_solved);
    }

    public function test_submit_score_zeroes_xp_once_the_server_tracked_hint_count_reaches_the_break_count(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user);
        $shaded = $this->solveLevel($puzzle);

        // Seeds the same cache entry BurnfrontController::incrementCampaignHints()
        // would have built up via real /hint calls — exercised end to end by
        // test_hint_endpoint_increments_the_server_tracked_hint_count() below.
        Cache::put(CampaignService::hintCacheKey($puzzle['token']), $puzzle['breaks'], now()->addMinutes(5));

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'token' => $puzzle['token'],
            'shaded' => $shaded,
        ]);

        $response->assertJson(['xpAwarded' => 0]);
        $this->assertSame(0, CampaignProfile::where('user_id', $user->id)->value('total_xp'));
    }

    /**
     * A campaign solve is logged like daily and endless solves, so it
     * counts for the weekly goal, and the response says what's next.
     */
    public function test_submit_score_logs_the_solve_and_reports_progress(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'token' => $puzzle['token'],
            'shaded' => $this->solveLevel($puzzle),
            'moves' => [['type' => 'mark', 'cell' => 1]],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('progress.weekSolved', 1);
        $response->assertJsonPath('progress.totalSolved', 1);
        $response->assertJsonPath('nextFire.kind', 'campaign');
        $this->assertSame(1, GamePlay::where('user_id', $user->id)->where('mode', 'campaign')->count());
    }

    public function test_submit_score_rejects_an_incorrect_board(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'token' => $puzzle['token'],
            'shaded' => [],
        ]);

        $response->assertStatus(422);
        // A profile row may already exist (submitScore() reads it to know
        // which level to score against before validating the board), but
        // a rejected board must never grant XP or count as a solve.
        $profile = CampaignProfile::where('user_id', $user->id)->first();
        $this->assertTrue($profile === null || ($profile->total_xp === 0 && $profile->puzzles_solved === 0));
    }

    /**
     * Regression test for a reviewer-flagged risk: submitScore() used to
     * accept spark/clues straight from the request body, so a client could
     * fabricate a trivial valid-looking board (e.g. an almost-empty clue
     * set) that satisfies Engine::exactCheck() without ever solving an
     * incident /campaign/puzzle actually generated. Now the board comes
     * only from the signed token, so a fabricated or missing one is
     * rejected outright regardless of what "shaded" claims.
     */
    public function test_submit_score_rejects_a_missing_or_fabricated_token(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/campaign/score', [
            'shaded' => [],
        ])->assertStatus(422);

        $this->actingAs($user)->postJson('/campaign/score', [
            'token' => 'not-a-real-token',
            'shaded' => [],
        ])->assertStatus(422);

        $this->assertSame(0, CampaignProfile::count());
    }

    /**
     * Regression test: a token must be redeemable for XP exactly once —
     * otherwise a client could keep replaying the same solved board to farm
     * XP indefinitely without ever generating (or solving) a new incident.
     */
    public function test_submit_score_rejects_reusing_the_same_token_twice(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user);
        $shaded = $this->solveLevel($puzzle);

        $this->actingAs($user)->postJson('/campaign/score', [
            'token' => $puzzle['token'],
            'shaded' => $shaded,
        ])->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'token' => $puzzle['token'],
            'shaded' => $shaded,
        ]);

        $response->assertStatus(409);
        $this->assertSame(30, CampaignProfile::where('user_id', $user->id)->value('total_xp'));
        $this->assertSame(1, CampaignProfile::where('user_id', $user->id)->value('puzzles_solved'));
    }

    public function test_submit_score_ignores_any_client_supplied_level_since_only_the_token_is_trusted(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user); // this account is at level 1
        $shaded = $this->solveLevel($puzzle);

        // decodeRun() never reads a "level" field from the request body at
        // all — the level is baked into the signed token itself — so this
        // extra field is inert either way. It's still worth asserting the
        // award matches level 1 (the token's real board, a 5 by 5), not 16.
        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'level' => 16,
            'token' => $puzzle['token'],
            'shaded' => $shaded,
        ]);

        $response->assertJson(['xpAwarded' => 30]);
    }

    public function test_leveling_up_crosses_into_the_next_level(): void
    {
        $user = User::factory()->create();
        CampaignProfile::create([
            'user_id' => $user->id,
            'total_xp' => Progression::xpForLevel(2) - 30,
            'puzzles_solved' => 1,
        ]);

        $puzzle = $this->issuePuzzle($user);
        $shaded = $this->solveLevel($puzzle);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'token' => $puzzle['token'],
            'shaded' => $shaded,
        ]);

        $response->assertJson(['level' => 2, 'leveledUp' => true, 'reward' => ['rankUp' => false]]);
        $response->assertJsonPath('nextFire.level', 2);
    }

    /** Level 5 is where the second rank starts, with its 6 by 6 fires. */
    public function test_reaching_level_five_moves_up_a_rank(): void
    {
        $user = User::factory()->create();
        CampaignProfile::create([
            'user_id' => $user->id,
            'total_xp' => Progression::xpForLevel(5) - 1,
            'puzzles_solved' => 12,
        ]);

        $puzzle = $this->issuePuzzle($user);
        $this->assertSame(4, $puzzle['level']);

        $response = $this->actingAs($user)->postJson('/campaign/score', [
            'token' => $puzzle['token'],
            'shaded' => $this->solveLevel($puzzle),
        ]);

        $response->assertJson(['level' => 5, 'leveledUp' => true, 'reward' => ['rankUp' => true]]);
        $response->assertJsonPath('progress.rankKey', 'firefighter');
        $response->assertJsonPath('nextFire.rows', 6);
    }

    /**
     * Levels never stop. Past the 20 rungs of the ladder the campaign keeps
     * handing out its hardest kinds of board, a different one each fire.
     */
    public function test_the_campaign_keeps_going_past_the_top_of_the_ladder(): void
    {
        $this->assertSame(20, CampaignService::rungFor(20, 3));
        $this->assertSame(13, CampaignService::rungFor(21, 0));
        $this->assertSame(14, CampaignService::rungFor(21, 1));
        $this->assertSame(20, CampaignService::rungFor(40, 7));
        $this->assertSame(13, CampaignService::rungFor(40, 8));

        $user = User::factory()->create();
        CampaignProfile::create([
            'user_id' => $user->id,
            'total_xp' => Progression::xpForLevel(30),
            'puzzles_solved' => 0,
        ]);

        $this->actingAs($user)->get('/campaign')->assertInertia(fn (Assert $page) => $page
            ->where('standing.level', 30)
            ->where('standing.rankKey', 'legend')
            ->where('standing.nextRankLevel', null)
            ->where('ranks.6.state', 'now')
            ->where('next.rows', 8)
        );
    }

    public function test_hint_endpoint_accepts_the_campaign_difficulty_via_token(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user);

        $response = $this->actingAs($user)->getJson('/hint?'.http_build_query([
            'difficulty' => 'campaign',
            'token' => $puzzle['token'],
            'shaded' => json_encode([]),
            'open' => json_encode([]),
        ]));

        $response->assertStatus(200);
        $response->assertJsonStructure(['status']);
    }

    /**
     * Regression test for a reviewer-flagged risk: hints_used used to be a
     * self-reported field on the score request, so a client could hint the
     * entire board and still submit hints_used: 0 for full XP. Now the
     * server counts hints itself, keyed to this run's token.
     */
    public function test_hint_endpoint_increments_the_server_tracked_hint_count_for_campaign(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user);

        $this->assertNull(Cache::get(CampaignService::hintCacheKey($puzzle['token'])));

        $response = $this->actingAs($user)->getJson('/hint?'.http_build_query([
            'difficulty' => 'campaign',
            'token' => $puzzle['token'],
            'shaded' => json_encode([]),
            'open' => json_encode([]),
        ]));

        $response->assertJson(['status' => 'forced']);
        $this->assertSame(1, Cache::get(CampaignService::hintCacheKey($puzzle['token'])));
    }

    public function test_hint_endpoint_rejects_the_campaign_difficulty_without_a_token(): void
    {
        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'campaign',
        ]));

        $response->assertStatus(422);
    }

    public function test_solve_endpoint_returns_the_full_solution_for_the_campaign_difficulty_via_token(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->issuePuzzle($user);

        $response = $this->actingAs($user)->getJson('/solve?'.http_build_query([
            'difficulty' => 'campaign',
            'token' => $puzzle['token'],
        ]));

        $response->assertStatus(200);
        $this->assertCount($puzzle['breaks'], $response->json('solution'));
    }

    /**
     * Issues a real run through the actual /campaign/puzzle endpoint (not
     * PuzzleService directly) so the returned token is genuinely valid for
     * hint()/solve()/submitScore() — those endpoints only ever accept a
     * token this route produced.
     *
     * @return array{difficulty: string, rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: string, blurb: string, token: string}
     */
    private function issuePuzzle(User $user): array
    {
        return $this->actingAs($user)->getJson('/campaign/puzzle')->json();
    }

    /** @return list<int> the shaded cells of a valid solution for an issuePuzzle()-shaped payload */
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
