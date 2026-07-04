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
