<?php

namespace Tests\Feature;

use App\Models\DailyIncident;
use App\Models\DailyScore;
use App\Models\EndlessScore;
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

    public function test_hint_endpoint_increments_the_daily_hint_counter_only_on_a_forced_firebreak(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $clues = [];
        foreach ($daily['clues'] as [$cell, $minute]) {
            $clues[$cell] = $minute;
        }
        $puzzle = new Puzzle($daily['rows'], $daily['cols'], $daily['spark'], $clues, $daily['breaks']);
        $state = Engine::deductionSolve($puzzle);
        $this->assertNotNull($state);

        // Ask for a hint with a clean slate: the very first forced deduction
        // should count. Asking again with the exact same (still empty)
        // board replays the same forced cell and counts again — the
        // counter tracks hints drawn, not distinct cells.
        $this->actingAs($user)->getJson('/hint?'.http_build_query([
            'difficulty' => 'daily',
            'spark' => $daily['spark'],
            'clues' => json_encode($daily['clues']),
            'shaded' => '[]',
            'open' => '[]',
        ]))->assertJson(['status' => 'forced']);

        $this->actingAs($user)->getJson('/hint?'.http_build_query([
            'difficulty' => 'daily',
            'spark' => $daily['spark'],
            'clues' => json_encode($daily['clues']),
            'shaded' => '[]',
            'open' => '[]',
        ]))->assertJson(['status' => 'forced']);

        $shaded = $this->solveDaily($daily);
        $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $shaded,
        ])->assertJson(['hints_used' => 2]);

        $score = DailyScore::where('user_id', $user->id)->whereDate('date', now('UTC')->toDateString())->first();
        $this->assertSame(2, $score->hints_used);
    }

    public function test_hint_endpoint_does_not_count_hints_for_endless_difficulties(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();

        $puzzle = $this->puzzles()->generate('lookout');
        $this->actingAs($user)->getJson('/hint?'.http_build_query([
            'difficulty' => 'lookout',
            'spark' => $puzzle['spark'],
            'clues' => json_encode($puzzle['clues']),
            'shaded' => '[]',
            'open' => '[]',
        ]));

        $shaded = $this->solveDaily($daily);
        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $shaded,
        ]);

        $response->assertJson(['hints_used' => 0]);
    }

    public function test_daily_leaderboard_flags_a_zero_hint_solve_as_clean(): void
    {
        $date = now('UTC')->toDateString();
        $user = User::factory()->create(['name' => 'Clean Analyst']);
        DailyScore::create(['user_id' => $user->id, 'date' => $date, 'time_ms' => 1000, 'hints_used' => 0]);

        $response = $this->getJson('/daily/leaderboard');

        $response->assertJson(['entries' => [['hints_used' => 0]]]);
    }

    public function test_start_screen_reports_a_zero_streak_for_a_fresh_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('dailyStatus.streak.current', 0)
            ->where('dailyStatus.streak.best', 0)
        );
    }

    public function test_start_screen_reports_a_multi_day_streak(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-07-02 12:00:00', 'UTC'));
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-01', 'time_ms' => 1000]);
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-02', 'time_ms' => 1000]);

        $response = $this->actingAs($user)->get('/');
        Carbon::setTestNow();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('dailyStatus.streak.current', 2)
            ->where('dailyStatus.streak.best', 2)
        );
    }

    public function test_start_screen_streak_is_still_current_if_yesterday_was_solved_but_not_yet_today(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-07-02 12:00:00', 'UTC'));
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-01', 'time_ms' => 1000]);

        $response = $this->actingAs($user)->get('/');
        Carbon::setTestNow();

        $response->assertInertia(fn (Assert $page) => $page->where('dailyStatus.streak.current', 1));
    }

    public function test_start_screen_streak_resets_after_a_missed_day(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-07-03 12:00:00', 'UTC'));
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-06-30', 'time_ms' => 1000]);
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-01', 'time_ms' => 1000]);
        // 2026-07-02 skipped
        DailyScore::create(['user_id' => $user->id, 'date' => '2026-07-03', 'time_ms' => 1000]);

        $response = $this->actingAs($user)->get('/');
        Carbon::setTestNow();

        $response->assertInertia(fn (Assert $page) => $page
            ->where('dailyStatus.streak.current', 1)
            ->where('dailyStatus.streak.best', 2)
        );
    }

    public function test_daily_history_lists_past_solved_incidents_with_name_and_time(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();
        $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $this->solveDaily($daily),
        ])->assertStatus(200);

        $response = $this->actingAs($user)->get('/daily/history');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/DailyHistory')
            ->where('entries.0.date', now('UTC')->toDateString())
            ->where('entries.0.name', $daily['name'])
            ->has('entries.0.time_ms')
            ->where('streak.current', 1)
        );
    }

    public function test_daily_history_requires_authentication(): void
    {
        $this->get('/daily/history')->assertRedirect('/login');
    }

    public function test_daily_history_play_replays_a_solved_incidents_board_and_solution(): void
    {
        $user = User::factory()->create();
        $daily = $this->actingAs($user)->getJson('/daily')->json();
        $expectedSolution = $this->solveDaily($daily);
        $this->actingAs($user)->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => $expectedSolution,
        ])->assertStatus(200);

        $date = now('UTC')->toDateString();
        $response = $this->actingAs($user)->get("/daily/history/play?date={$date}");

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Play')
            ->where('mode', 'archive')
            ->where('archivePuzzle.date', $date)
            ->where('archivePuzzle.name', $daily['name'])
            ->has('archivePuzzle.solution')
        );
    }

    public function test_daily_history_play_rejects_a_date_this_account_never_scored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/daily'); // generates + persists today's incident, but no score

        $date = now('UTC')->toDateString();
        $this->actingAs($user)->get("/daily/history/play?date={$date}")->assertStatus(404);
    }

    public function test_daily_history_play_rejects_a_malformed_date(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/daily/history/play?date=not-a-date')->assertStatus(422);
    }

    public function test_submit_endless_score_records_a_verified_time_and_flags_an_improvement(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->puzzles()->generate('lookout');
        $shaded = $this->solveEndless($puzzle);

        $response = $this->actingAs($user)->postJson('/endless/score', [
            'difficulty' => 'lookout',
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => $shaded,
            'time_ms' => 5000,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['solved_count' => 1, 'best_time_ms' => 5000, 'improved' => true]);

        $record = EndlessScore::where('user_id', $user->id)->where('difficulty', 'lookout')->first();
        $this->assertNotNull($record);
        $this->assertSame(1, $record->solved_count);
        $this->assertSame(5000, $record->best_time_ms);
    }

    public function test_submit_endless_score_only_updates_best_time_when_actually_faster(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->puzzles()->generate('lookout');
        $shaded = $this->solveEndless($puzzle);

        $this->actingAs($user)->postJson('/endless/score', [
            'difficulty' => 'lookout',
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => $shaded,
            'time_ms' => 5000,
        ])->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/endless/score', [
            'difficulty' => 'lookout',
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => $shaded,
            'time_ms' => 9000,
        ]);

        $response->assertJson(['solved_count' => 2, 'best_time_ms' => 5000, 'improved' => false]);
    }

    public function test_submit_endless_score_rejects_an_incorrect_board(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->puzzles()->generate('lookout');

        $response = $this->actingAs($user)->postJson('/endless/score', [
            'difficulty' => 'lookout',
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => [],
            'time_ms' => 5000,
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, EndlessScore::count());
    }

    public function test_submit_endless_score_rejects_the_custom_difficulty(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/endless/score', [
            'difficulty' => 'custom',
            'spark' => 0,
            'clues' => [],
            'shaded' => [],
            'time_ms' => 1000,
        ]);

        $response->assertStatus(422);
    }

    public function test_submit_endless_score_records_a_solve_for_the_untimed_cold_case_tier_without_a_time(): void
    {
        $user = User::factory()->create();
        $puzzle = $this->puzzles()->generate('coldcase');

        $response = $this->actingAs($user)->postJson('/endless/score', [
            'difficulty' => 'coldcase',
            'spark' => $puzzle['spark'],
            'clues' => $puzzle['clues'],
            'shaded' => $this->solveEndless($puzzle),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['solved_count' => 1, 'best_time_ms' => null, 'improved' => false]);

        $record = EndlessScore::where('user_id', $user->id)->where('difficulty', 'coldcase')->first();
        $this->assertNotNull($record);
        $this->assertSame(1, $record->solved_count);
        $this->assertNull($record->best_time_ms);
    }

    public function test_submit_endless_score_requires_authentication(): void
    {
        $this->postJson('/endless/score', [
            'difficulty' => 'lookout',
            'spark' => 0,
            'clues' => [],
            'shaded' => [],
            'time_ms' => 1000,
        ])->assertStatus(401);
    }

    public function test_endless_setup_screen_reports_a_signed_in_players_best_times(): void
    {
        $user = User::factory()->create();
        EndlessScore::create(['user_id' => $user->id, 'difficulty' => 'lookout', 'solved_count' => 3, 'best_time_ms' => 4200]);

        $response = $this->actingAs($user)->get('/endless');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('bestTimes.lookout.solvedCount', 3)
            ->where('bestTimes.lookout.bestTimeMs', 4200)
        );
    }

    public function test_endless_setup_screen_reports_no_best_times_for_a_guest(): void
    {
        $response = $this->get('/endless');

        $response->assertInertia(fn (Assert $page) => $page->where('bestTimes', []));
    }

    public function test_endless_play_screen_reports_whether_the_player_is_authenticated(): void
    {
        $user = User::factory()->create();

        $this->get('/endless/play')->assertInertia(fn (Assert $page) => $page->where('authenticated', false));
        $this->actingAs($user)->get('/endless/play')->assertInertia(fn (Assert $page) => $page->where('authenticated', true));
    }

    public function test_game_history_lists_every_tier_with_a_default_row_for_untried_ones(): void
    {
        $user = User::factory()->create();
        EndlessScore::create(['user_id' => $user->id, 'difficulty' => 'lookout', 'solved_count' => 2, 'best_time_ms' => 3000]);

        $response = $this->actingAs($user)->get('/game/history');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/GameHistory')
            ->where('tiers.0.solvedCount', 2)
            ->where('tiers.0.bestTimeMs', 3000)
            ->where('tiers.1.solvedCount', 0)
            ->where('tiers.1.bestTimeMs', null)
        );
    }

    public function test_game_history_requires_authentication(): void
    {
        $this->get('/game/history')->assertRedirect('/login');
    }

    public function test_game_history_reports_a_trainee_rank_and_no_badges_for_a_fresh_account(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/game/history');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('career.rank.title', 'Trainee Analyst')
            ->where('career.rank.totalSolved', 0)
            ->where('career.rank.nextTitle', 'Field Analyst')
            ->where('career.rank.nextThreshold', 5)
            ->where('career.badges.0.earned', false)
        );
    }

    public function test_game_history_career_rank_counts_daily_and_endless_solves_together(): void
    {
        $user = User::factory()->create();
        EndlessScore::create(['user_id' => $user->id, 'difficulty' => 'lookout', 'solved_count' => 3, 'best_time_ms' => 3000]);
        DailyScore::create(['user_id' => $user->id, 'date' => now('UTC')->toDateString(), 'time_ms' => 1000, 'hints_used' => 0]);

        $response = $this->actingAs($user)->get('/game/history');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('career.rank.totalSolved', 4)
            ->where('career.rank.title', 'Trainee Analyst')
            ->where('career.badges.0.earned', true) // first_incident
            ->where('career.badges.1.earned', true) // clean_reconstruction
        );
    }

    public function test_game_history_awards_the_cold_case_badge_only_after_a_cold_case_solve(): void
    {
        $user = User::factory()->create();

        $before = $this->actingAs($user)->get('/game/history');
        $before->assertInertia(fn (Assert $page) => $page->where('career.badges.5.earned', false));

        EndlessScore::create(['user_id' => $user->id, 'difficulty' => 'coldcase', 'solved_count' => 1]);

        $after = $this->actingAs($user)->get('/game/history');
        $after->assertInertia(fn (Assert $page) => $page->where('career.badges.5.earned', true));
    }

    public function test_daily_incident_is_persisted_the_first_time_it_is_generated(): void
    {
        $user = User::factory()->create();
        $date = now('UTC')->toDateString();

        $this->assertSame(0, DailyIncident::count());

        $this->actingAs($user)->getJson('/daily');

        $incident = DailyIncident::whereDate('date', $date)->first();
        $this->assertNotNull($incident);
        $this->assertSame(1, DailyIncident::count());

        // A second request the same day must not try (and fail) to
        // persist a duplicate row for the same date.
        $this->actingAs($user)->getJson('/daily');
        $this->assertSame(1, DailyIncident::count());
    }

    private function puzzles(): PuzzleService
    {
        return app(PuzzleService::class);
    }

    /** @return list<int> the shaded cells of a valid solution for a /puzzle-shaped payload (spark/clues/rows/cols/breaks) */
    private function solveEndless(array $puzzle): array
    {
        $clues = [];
        foreach ($puzzle['clues'] as [$cell, $minute]) {
            $clues[$cell] = $minute;
        }
        $p = new Puzzle($puzzle['rows'], $puzzle['cols'], $puzzle['spark'], $clues, $puzzle['breaks']);

        $state = Engine::deductionSolve($p);
        $this->assertNotNull($state, 'incident should be solvable by pure deduction');

        $shaded = [];
        foreach ($state as $cell => $value) {
            if ($value === Engine::SHADED) {
                $shaded[] = $cell;
            }
        }

        return $shaded;
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
