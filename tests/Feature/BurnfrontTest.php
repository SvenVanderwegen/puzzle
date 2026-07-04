<?php

namespace Tests\Feature;

use App\Models\DailyScore;
use App\Models\User;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BurnfrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_start_screen_renders(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Burnfront', false);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Start')
            ->where('dailyStatus', null)
        );
    }

    public function test_the_start_screen_reports_daily_status_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $this->solveDaily($daily),
        ])->assertStatus(200);

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Start')
            ->where('dailyStatus.alreadyScored', true)
            ->has('dailyStatus.scoreTimeMs')
        );
    }

    public function test_the_endless_setup_screen_renders(): void
    {
        $response = $this->get('/endless');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/EndlessSetup')
            ->has('difficulties.lookout')
            ->has('difficulties.crew')
            ->has('difficulties.hotshot')
            ->has('customBounds.minDim')
            ->has('customBounds.maxDim')
            ->has('customBounds.minBreaks')
            ->has('customBounds.breaksRatio')
        );
    }

    public function test_the_endless_play_screen_renders_with_the_default_difficulty(): void
    {
        $response = $this->get('/endless/play');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('mode', 'endless')
            ->where('difficulty', PuzzleService::DEFAULT_DIFFICULTY)
            ->has('difficulties.lookout')
        );
    }

    public function test_the_endless_play_screen_honors_a_requested_difficulty(): void
    {
        $response = $this->get('/endless/play?difficulty=hotshot');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('difficulty', 'hotshot')
        );
    }

    public function test_the_endless_play_screen_falls_back_to_the_default_difficulty_for_an_unknown_tier(): void
    {
        $response = $this->get('/endless/play?difficulty=arsonist');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('difficulty', PuzzleService::DEFAULT_DIFFICULTY)
        );
    }

    public function test_the_endless_play_screen_honors_a_valid_custom_grid(): void
    {
        $response = $this->get('/endless/play?difficulty=custom&rows=6&cols=7&breaks=10');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('difficulty', 'custom')
            ->where('difficulties.custom.rows', 6)
            ->where('difficulties.custom.cols', 7)
            ->where('difficulties.custom.breaks', 10)
            ->has('difficulties.lookout')
        );
    }

    public function test_the_endless_play_screen_falls_back_to_the_default_difficulty_for_an_invalid_custom_grid(): void
    {
        $response = $this->get('/endless/play?difficulty=custom&rows=999&cols=7&breaks=10');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('difficulty', PuzzleService::DEFAULT_DIFFICULTY)
        );
    }

    public function test_the_how_to_screen_renders(): void
    {
        $response = $this->get('/how-to');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page->component('Burnfront/HowTo'));
    }

    public function test_the_daily_play_screen_requires_authentication(): void
    {
        $response = $this->get('/daily/play');

        $response->assertRedirect('/login');
    }

    public function test_the_daily_play_screen_renders_for_a_signed_in_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/daily/play');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('mode', 'daily')
        );
    }

    public function test_puzzle_endpoint_generates_a_lookout_incident(): void
    {
        $response = $this->getJson('/puzzle?difficulty=lookout');

        $response->assertStatus(200);
        $response->assertJsonStructure(['difficulty', 'rows', 'cols', 'breaks', 'spark', 'clues', 'name', 'blurb']);
        $response->assertJson([
            'difficulty' => 'lookout',
            'rows' => 5,
            'cols' => 5,
            'breaks' => 4,
        ]);
        $this->assertIsString($response->json('name'));
        $this->assertIsString($response->json('blurb'));
    }

    public function test_puzzle_endpoint_generates_a_division_supervisor_incident(): void
    {
        $response = $this->getJson('/puzzle?difficulty=division');

        $response->assertStatus(200);
        $response->assertJson([
            'difficulty' => 'division',
            'rows' => 8,
            'cols' => 8,
            'breaks' => 17,
        ]);
    }

    public function test_puzzle_endpoint_generates_a_cold_case_incident(): void
    {
        $response = $this->getJson('/puzzle?difficulty=coldcase');

        $response->assertStatus(200);
        $response->assertJson([
            'difficulty' => 'coldcase',
            'rows' => 7,
            'cols' => 7,
            'breaks' => 12,
        ]);
    }

    public function test_puzzle_endpoint_generates_a_custom_incident(): void
    {
        $response = $this->getJson('/puzzle?'.http_build_query([
            'difficulty' => 'custom',
            'rows' => 6,
            'cols' => 7,
            'breaks' => 10,
        ]));

        $response->assertStatus(200);
        $response->assertJson([
            'difficulty' => 'custom',
            'rows' => 6,
            'cols' => 7,
            'breaks' => 10,
        ]);
    }

    public function test_puzzle_endpoint_rejects_a_custom_grid_outside_bounds(): void
    {
        $response = $this->getJson('/puzzle?'.http_build_query([
            'difficulty' => 'custom',
            'rows' => 999,
            'cols' => 7,
            'breaks' => 10,
        ]));

        $response->assertStatus(422);
    }

    public function test_puzzle_endpoint_rejects_a_custom_grid_missing_params(): void
    {
        $response = $this->getJson('/puzzle?difficulty=custom&rows=6&cols=7');

        $response->assertStatus(422);
    }

    public function test_puzzle_endpoint_defaults_to_lookout(): void
    {
        $response = $this->getJson('/puzzle');

        $response->assertStatus(200);
        $response->assertJson(['difficulty' => 'lookout']);
    }

    public function test_puzzle_endpoint_rejects_unknown_difficulty(): void
    {
        $response = $this->getJson('/puzzle?difficulty=arsonist');

        $response->assertStatus(422);
    }

    public function test_hint_endpoint_offers_a_forced_deduction_for_a_fresh_incident(): void
    {
        $puzzle = $this->getJson('/puzzle?difficulty=lookout')->json();

        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'lookout',
            'spark' => $puzzle['spark'],
            'clues' => json_encode($puzzle['clues']),
            'shaded' => json_encode([]),
        ]));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'forced']);
        $response->assertJsonStructure(['status', 'cell']);
    }

    public function test_hint_endpoint_offers_a_forced_deduction_for_a_custom_incident(): void
    {
        $puzzle = $this->getJson('/puzzle?'.http_build_query([
            'difficulty' => 'custom',
            'rows' => 6,
            'cols' => 7,
            'breaks' => 10,
        ]))->json();

        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'custom',
            'rows' => 6,
            'cols' => 7,
            'breaks' => 10,
            'spark' => $puzzle['spark'],
            'clues' => json_encode($puzzle['clues']),
            'shaded' => json_encode([]),
        ]));

        $response->assertStatus(200);
        $this->assertContains($response->json('status'), ['forced', 'complete']);
    }

    public function test_hint_endpoint_rejects_a_custom_grid_outside_bounds(): void
    {
        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'custom',
            'rows' => 999,
            'cols' => 7,
            'breaks' => 10,
            'spark' => 0,
            'clues' => json_encode([]),
        ]));

        $response->assertStatus(422);
    }

    public function test_hint_endpoint_rejects_unknown_difficulty(): void
    {
        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'arsonist',
            'spark' => 0,
            'clues' => json_encode([]),
        ]));

        $response->assertStatus(422);
    }

    public function test_hint_endpoint_rejects_a_spark_outside_the_grid(): void
    {
        $puzzle = $this->getJson('/puzzle?difficulty=lookout')->json();

        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'lookout',
            'spark' => 999,
            'clues' => json_encode($puzzle['clues']),
        ]));

        $response->assertStatus(422);
    }

    public function test_hint_endpoint_rejects_a_shaded_cell_that_is_actually_a_clue(): void
    {
        $puzzle = $this->getJson('/puzzle?difficulty=lookout')->json();

        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'lookout',
            'spark' => $puzzle['spark'],
            'clues' => json_encode($puzzle['clues']),
            'shaded' => json_encode([$puzzle['clues'][0][0]]),
        ]));

        $response->assertStatus(422);
    }

    /**
     * The hint system only ever surfaces a forced *firebreak* — any forced
     * "stays clear" step along the way is absorbed silently server-side (see
     * BurnfrontController::hint()), so the player never needs to feed those
     * back as committed open cells. Walks the fixed demo instance from
     * EngineTest, feeding only the returned shaded cells back each time, and
     * asserts every verdict is a firebreak (never 'open') until the board is
     * fully accounted for, with no cell ever suggested twice.
     */
    public function test_hint_endpoint_only_ever_surfaces_firebreak_deductions(): void
    {
        $spark = 15;
        $clues = [[9, 8], [12, 5], [16, 1], [21, 2], [23, 8]];

        $shaded = [];
        $seen = [];

        for ($i = 0; $i < 25; $i++) {
            $response = $this->getJson('/hint?'.http_build_query([
                'difficulty' => 'lookout',
                'spark' => $spark,
                'clues' => json_encode($clues),
                'shaded' => json_encode($shaded),
            ]));

            $response->assertStatus(200);
            $status = $response->json('status');

            if ($status !== 'forced') {
                $this->assertSame('complete', $status);
                break;
            }

            $cell = $response->json('cell');
            $this->assertNotContains($cell, $seen, "Cell {$cell} was suggested more than once.");
            $seen[] = $cell;
            $shaded[] = $cell;
        }

        $this->assertSame('complete', $response->json('status'));
        sort($shaded);
        $this->assertSame([8, 11, 17, 22], $shaded);
    }

    /**
     * The daily puzzle's board, clues and token are signed-in-only content
     * (see routes/web.php) — a guest must never see them, or they could
     * solve the shared board offline and race the clock right after signing
     * in, defeating the timing gate entirely.
     */
    public function test_daily_endpoint_requires_authentication(): void
    {
        $response = $this->getJson('/daily');

        $response->assertStatus(401);
    }

    public function test_daily_endpoint_returns_todays_incident(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/daily');

        $response->assertStatus(200);
        $response->assertJsonStructure(['difficulty', 'rows', 'cols', 'breaks', 'spark', 'clues', 'name', 'blurb', 'date', 'token']);
        $response->assertJson([
            'difficulty' => 'daily',
            'date' => now('UTC')->toDateString(),
        ]);
    }

    public function test_daily_endpoint_is_deterministic_across_requests(): void
    {
        $user = User::factory()->create();
        $a = $this->actingAs($user)->getJson('/daily')->json();
        $b = $this->actingAs($user)->getJson('/daily')->json();

        unset($a['token'], $b['token']); // encrypted separately each time, ciphertext is expected to differ
        $this->assertSame($a, $b);
    }

    public function test_daily_endpoint_reports_not_yet_scored_for_a_fresh_user(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->getJson('/daily');

        $response->assertJson(['alreadyScored' => false, 'scoreTimeMs' => null]);
    }

    public function test_daily_puzzle_difficulty_is_rejected_by_the_puzzle_endpoint(): void
    {
        $response = $this->getJson('/puzzle?difficulty=daily');

        $response->assertStatus(422);
    }

    public function test_hint_endpoint_accepts_the_daily_difficulty(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'daily',
            'spark' => $daily['spark'],
            'clues' => json_encode($daily['clues']),
        ]));

        $response->assertStatus(200);
        $this->assertContains($response->json('status'), ['forced', 'complete']);
    }

    /**
     * Mirrors Engine's misplacedShaded coverage at the HTTP boundary: three
     * of the demo instance's four true breaks (8, 11, 17 — see EngineTest's
     * demoPuzzle/demoBreaks) are committed correctly, plus one wrong guess
     * (5) in place of the real fourth break (22, left uncommitted). The
     * endpoint should report the contradiction and pin it on the wrong cell
     * specifically, not on any of the correctly placed ones.
     */
    public function test_hint_endpoint_flags_a_misplaced_firebreak_on_contradiction(): void
    {
        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'lookout',
            'spark' => 15,
            'clues' => json_encode([[9, 8], [12, 5], [16, 1], [21, 2], [23, 8]]),
            'shaded' => json_encode([8, 11, 17, 5]),
        ]));

        $response->assertStatus(200);
        $response->assertJson(['status' => 'contradiction', 'wrong' => [5]]);
    }

    public function test_solve_endpoint_returns_the_full_solution(): void
    {
        $puzzle = $this->getJson('/puzzle?difficulty=lookout')->json();

        $response = $this->getJson('/solve?'.http_build_query([
            'difficulty' => 'lookout',
            'spark' => $puzzle['spark'],
            'clues' => json_encode($puzzle['clues']),
        ]));

        $response->assertStatus(200);
        $solution = $response->json('solution');
        $this->assertIsArray($solution);
        $this->assertCount($puzzle['breaks'], $solution);

        $clueCells = array_map(fn ($pair) => $pair[0], $puzzle['clues']);
        foreach ($solution as $cell) {
            $this->assertIsInt($cell);
            $this->assertNotSame($puzzle['spark'], $cell);
            $this->assertNotContains($cell, $clueCells);
        }
    }

    public function test_solve_endpoint_rejects_unknown_difficulty(): void
    {
        $response = $this->getJson('/solve?'.http_build_query([
            'difficulty' => 'arsonist',
            'spark' => 0,
            'clues' => json_encode([]),
        ]));

        $response->assertStatus(422);
    }

    public function test_solve_endpoint_returns_the_full_solution_for_a_custom_incident(): void
    {
        $puzzle = $this->getJson('/puzzle?'.http_build_query([
            'difficulty' => 'custom',
            'rows' => 6,
            'cols' => 7,
            'breaks' => 10,
        ]))->json();

        $response = $this->getJson('/solve?'.http_build_query([
            'difficulty' => 'custom',
            'rows' => 6,
            'cols' => 7,
            'breaks' => 10,
            'spark' => $puzzle['spark'],
            'clues' => json_encode($puzzle['clues']),
        ]));

        $response->assertStatus(200);
        $this->assertCount(10, $response->json('solution'));
    }

    public function test_submit_daily_score_requires_authentication(): void
    {
        $response = $this->postJson('/daily/score', [
            'token' => 'not-a-real-token',
            'shaded' => [],
        ]);

        $response->assertStatus(401);
    }

    /**
     * The daily flow always fetches /daily while signed in before playing
     * (this is what binds the account's start time — see
     * BurnfrontController@daily), so every submission test here does the
     * same: acts as the user first, then fetches.
     */
    public function test_submit_daily_score_accepts_a_correct_board_and_records_a_verified_time(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $this->solveDaily($daily),
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['time_ms', 'rank']);
        $this->assertTrue(
            DailyScore::where('user_id', $user->id)->whereDate('date', now('UTC')->toDateString())->exists()
        );
    }

    /**
     * The "Solve" cheat button is meant to void the run instead of scoring
     * it (see BurnfrontController::solve()) — this asserts that void is
     * actually enforced server-side, not just left to the client's own
     * banner/state, by calling /solve directly and then attempting to POST
     * the correct board straight to /daily/score, bypassing the frontend
     * entirely.
     */
    public function test_submit_daily_score_rejects_a_submission_after_solve_was_called(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $solve = $this->actingAs($user)->getJson('/solve?'.http_build_query([
            'difficulty' => 'daily',
            'spark' => $daily['spark'],
            'clues' => json_encode($daily['clues']),
        ]));
        $solve->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $solve->json('solution'),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('burnfront_daily_scores', 0);
    }

    public function test_submit_daily_score_rejects_an_incorrect_board(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => [], // the daily tier requires 8 breaks; an empty board can't be correct
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('burnfront_daily_scores', 0);
    }

    public function test_submit_daily_score_rejects_a_stale_token(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $staleToken = Crypt::encryptString(json_encode(['date' => now('UTC')->subDay()->toDateString()]));

        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $staleToken,
            'shaded' => $this->solveDaily($daily),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('burnfront_daily_scores', 0);
    }

    public function test_submit_daily_score_rejects_a_submission_that_never_loaded_daily_while_signed_in(): void
    {
        // Fetched by a different account — the shared board/token this
        // populates never binds a start for $user below.
        $other = User::factory()->create();
        $daily = $this->actingAs($other)->getJson('/daily')->json();

        $user = User::factory()->create();
        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $this->solveDaily($daily),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('burnfront_daily_scores', 0);
    }

    public function test_submit_daily_score_rejects_a_duplicate_submission_for_the_same_day(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();
        $shaded = $this->solveDaily($daily);

        $this->actingAs($user)->postJson('/daily/score', ['token' => $daily['token'], 'shaded' => $shaded])
            ->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/daily/score', ['token' => $daily['token'], 'shaded' => $shaded]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('burnfront_daily_scores', 1);
    }

    /**
     * Regression test for the reported timing bug: /daily used to mint a
     * fresh issuedAt on every request, so a player who already knew the
     * solution could refetch right before submitting to reset their own
     * clock to ~0. The start time must instead be bound to this account's
     * *first* fetch of the day and stay fixed no matter how many times
     * /daily is refetched afterward.
     */
    public function test_submit_daily_score_measures_from_the_first_fetch_not_a_later_refetch(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-07-04 10:00:00', 'UTC'));
        $daily = $this->actingAs($user)->getJson('/daily')->json(); // binds start at 10:00:00

        Carbon::setTestNow(Carbon::parse('2026-07-04 10:05:00', 'UTC'));
        $this->actingAs($user)->getJson('/daily'); // refetching must not push the bound start later

        Carbon::setTestNow(Carbon::parse('2026-07-04 10:07:00', 'UTC'));
        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $this->solveDaily($daily),
        ]);

        Carbon::setTestNow();

        $response->assertStatus(200);
        // ~7 minutes elapsed since the first fetch, not ~2 minutes since the refetch.
        $this->assertEqualsWithDelta(7 * 60 * 1000, $response->json('time_ms'), 1000);
    }

    /**
     * Regression test for the reported bug where an authenticated player's
     * "already solved today" state was read from localStorage — stale if
     * they'd played earlier as a guest and only just signed in — instead of
     * from the server. /daily must report the server's own truth so a
     * signed-in player who hasn't actually posted a score yet gets a fresh,
     * playable board rather than being locked out.
     */
    public function test_daily_endpoint_reports_already_scored_after_this_account_has_submitted(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $this->solveDaily($daily),
        ])->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/daily');

        $response->assertJson(['alreadyScored' => true]);
        $this->assertIsInt($response->json('scoreTimeMs'));
    }

    /**
     * Regression test for the reported bug where reopening an already-solved
     * daily incident just showed an empty locked board instead of the
     * solution. /daily must hand back the firebreak placement (rederived by
     * pure deduction — see BurnfrontController::solveDaily()) once this
     * account has a recorded score, and never before.
     */
    public function test_daily_endpoint_includes_the_solution_once_already_scored(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();
        $expectedSolution = $this->solveDaily($daily);

        $this->assertArrayNotHasKey('solution', $daily);

        $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $expectedSolution,
        ])->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/daily');

        $response->assertJson(['alreadyScored' => true]);
        $solution = $response->json('solution');
        $this->assertIsArray($solution);
        sort($expectedSolution);
        sort($solution);
        $this->assertSame($expectedSolution, $solution);
    }

    public function test_daily_leaderboard_returns_entries_sorted_by_time(): void
    {
        $date = now('UTC')->toDateString();
        $fast = User::factory()->create(['name' => 'Fast Analyst']);
        $slow = User::factory()->create(['name' => 'Slow Analyst']);
        DailyScore::create(['user_id' => $fast->id, 'date' => $date, 'time_ms' => 1000]);
        DailyScore::create(['user_id' => $slow->id, 'date' => $date, 'time_ms' => 5000]);

        $response = $this->getJson('/daily/leaderboard');

        $response->assertStatus(200);
        $response->assertJson([
            'date' => $date,
            'entries' => [
                ['rank' => 1, 'name' => 'Fast Analyst', 'time_ms' => 1000],
                ['rank' => 2, 'name' => 'Slow Analyst', 'time_ms' => 5000],
            ],
        ]);
    }

    public function test_start_screen_reports_a_daily_streak(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00', 'UTC'));
        $user = User::factory()->create();
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-01', 'time_ms' => 1000]);
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-02', 'time_ms' => 1000]);
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-03', 'time_ms' => 1000]);

        $response = $this->actingAs($user)->get('/');

        Carbon::setTestNow();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Start')
            ->where('dailyStatus.streak.current', 3)
            ->where('dailyStatus.streak.best', 3)
        );
    }

    /**
     * Missing yesterday breaks the *current* streak even if an older run was
     * longer — dailyStreak() must report the older run only via `best`, and
     * a `current` of 0 rather than incorrectly carrying it forward.
     */
    public function test_daily_streak_resets_after_a_missed_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00', 'UTC'));
        $user = User::factory()->create();
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-06-20', 'time_ms' => 1000]);
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-01', 'time_ms' => 1000]);
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-02', 'time_ms' => 1000]);
        // 2026-07-03 (yesterday) is deliberately left unsolved.

        $response = $this->actingAs($user)->get('/');

        Carbon::setTestNow();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Start')
            ->where('dailyStatus.streak.current', 0)
            ->where('dailyStatus.streak.best', 2)
        );
    }

    public function test_submit_daily_score_records_a_clean_case_with_no_hints(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $this->solveDaily($daily),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['clean' => true]);
        $this->assertSame(0, DailyScore::first()->hints_used);
    }

    public function test_submit_daily_score_records_hints_used_and_is_not_clean(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'daily',
            'spark' => $daily['spark'],
            'clues' => json_encode($daily['clues']),
        ]))->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $this->solveDaily($daily),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['clean' => false]);
        $this->assertSame(1, DailyScore::first()->hints_used);
    }

    public function test_daily_leaderboard_reports_a_clean_flag_per_entry(): void
    {
        $date = now('UTC')->toDateString();
        $clean = User::factory()->create(['name' => 'Clean Analyst']);
        $hinted = User::factory()->create(['name' => 'Hinted Analyst']);
        DailyScore::create(['user_id' => $clean->id, 'date' => $date, 'time_ms' => 1000, 'hints_used' => 0]);
        DailyScore::create(['user_id' => $hinted->id, 'date' => $date, 'time_ms' => 2000, 'hints_used' => 2]);

        $response = $this->getJson('/daily/leaderboard');

        $response->assertJson([
            'entries' => [
                ['name' => 'Clean Analyst', 'clean' => true],
                ['name' => 'Hinted Analyst', 'clean' => false],
            ],
        ]);
    }

    public function test_daily_history_requires_authentication(): void
    {
        $response = $this->get('/daily/history');

        $response->assertRedirect('/login');
    }

    public function test_daily_history_reports_totals_and_streak(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04 12:00:00', 'UTC'));
        $user = User::factory()->create();
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-02', 'time_ms' => 4000, 'hints_used' => 0]);
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-03', 'time_ms' => 6000, 'hints_used' => 1]);

        $response = $this->actingAs($user)->get('/daily/history');

        Carbon::setTestNow();

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/DailyHistory')
            ->where('totalClosed', 2)
            ->where('bestTimeMs', 4000)
            ->where('averageTimeMs', 5000)
            ->where('cleanCount', 1)
            ->where('streak.current', 2)
            ->where('streak.best', 2)
            ->has('entries', 2)
        );
    }

    public function test_daily_archive_requires_authentication(): void
    {
        $response = $this->get('/daily/archive');

        $response->assertRedirect('/login');
    }

    public function test_daily_archive_lists_the_past_thirty_days(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/daily/archive');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/DailyArchive')
            ->has('entries', 30)
        );
    }

    public function test_daily_archive_puzzle_rejects_todays_date(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/daily/archive/'.now('UTC')->toDateString());

        $response->assertStatus(422);
    }

    public function test_daily_archive_puzzle_rejects_a_date_too_far_in_the_past(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/daily/archive/'.now('UTC')->subDays(60)->toDateString());

        $response->assertStatus(422);
    }

    public function test_daily_archive_puzzle_rejects_a_malformed_date(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/daily/archive/not-a-date');

        $response->assertStatus(422);
    }

    public function test_daily_archive_puzzle_is_deterministic_and_carries_no_scoring_token(): void
    {
        $user = User::factory()->create();
        $date = now('UTC')->subDays(3)->toDateString();

        $a = $this->actingAs($user)->getJson("/daily/archive/{$date}")->json();
        $b = $this->actingAs($user)->getJson("/daily/archive/{$date}")->json();

        $this->assertSame($a, $b);
        $this->assertArrayNotHasKey('token', $a);
    }

    public function test_daily_archive_play_screen_renders_for_a_valid_date(): void
    {
        $user = User::factory()->create();
        $date = now('UTC')->subDays(2)->toDateString();

        $response = $this->actingAs($user)->get("/daily/archive/{$date}/play");

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('mode', 'archive')
            ->where('archiveDate', $date)
        );
    }

    public function test_daily_archive_play_screen_404s_for_an_invalid_date(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/daily/archive/not-a-date/play');

        $response->assertStatus(404);
    }

    /**
     * Archive replays are tagged 'archive', a distinct difficulty string
     * from 'daily' (see PuzzleService::tierConfig()) specifically so that
     * solve()'s void-the-run behavior — which only fires for 'daily' — can
     * never reach into today's already-bound daily attempt just because a
     * player used the "Solve" cheat button on a past incident instead.
     */
    public function test_solving_an_archived_incident_does_not_void_todays_daily_score(): void
    {
        $user = User::factory()->create();
        $today = $this->actingAs($user)->getJson('/daily')->json();
        $pastDate = now('UTC')->subDays(5)->toDateString();
        $past = $this->actingAs($user)->getJson("/daily/archive/{$pastDate}")->json();

        $this->actingAs($user)->getJson('/solve?'.http_build_query([
            'difficulty' => 'archive',
            'spark' => $past['spark'],
            'clues' => json_encode($past['clues']),
        ]))->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $today['token'],
            'shaded' => $this->solveDaily($today),
        ]);

        $response->assertStatus(200);
    }

    /** @return list<int> the shaded cells of a valid solution for a /daily payload */
    private function solveDaily(array $daily): array
    {
        $clues = [];
        foreach ($daily['clues'] as [$cell, $minute]) {
            $clues[$cell] = $minute;
        }
        $puzzle = new Puzzle($daily['rows'], $daily['cols'], $daily['spark'], $clues, $daily['breaks']);

        $state = Engine::deductionSolve($puzzle);
        $this->assertNotNull($state, 'daily incident should be solvable by pure deduction');

        $shaded = [];
        foreach ($state as $cell => $value) {
            if ($value === Engine::SHADED) {
                $shaded[] = $cell;
            }
        }

        return $shaded;
    }
}
