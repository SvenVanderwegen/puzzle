<?php

namespace Tests\Feature;

use App\Models\DailyScore;
use App\Models\User;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BurnfrontTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_incident_desk_renders(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Burnfront', false);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Burnfront/Index')
            ->where('defaultDifficulty', PuzzleService::DEFAULT_DIFFICULTY)
            ->has('difficulties.lookout')
            ->has('difficulties.crew')
            ->has('difficulties.hotshot')
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
        $response->assertJsonStructure(['status', 'cell', 'state']);
        $this->assertContains($response->json('state'), ['break', 'open']);
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
     * A player accepting an "open" hint has no way to tell the server
     * except by sending it back as a committed open cell next time — if the
     * endpoint dropped that (only ever looking at `shaded`), an open verdict
     * would repeat forever instead of the search moving on. Walks the fixed
     * demo instance from EngineTest, feeding every returned cell back as
     * shaded or open depending on its verdict, and asserts no cell is ever
     * suggested twice.
     */
    public function test_hint_endpoint_advances_past_an_accepted_open_verdict(): void
    {
        $spark = 15;
        $clues = [[9, 8], [12, 5], [16, 1], [21, 2], [23, 8]];

        $shaded = [];
        $open = [];
        $seen = [];

        for ($i = 0; $i < 25; $i++) {
            $response = $this->getJson('/hint?'.http_build_query([
                'difficulty' => 'lookout',
                'spark' => $spark,
                'clues' => json_encode($clues),
                'shaded' => json_encode($shaded),
                'open' => json_encode($open),
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

            if ($response->json('state') === 'break') {
                $shaded[] = $cell;
            } else {
                $open[] = $cell;
            }
        }

        $this->assertSame('complete', $response->json('status'));
    }

    public function test_daily_endpoint_returns_todays_incident(): void
    {
        $response = $this->getJson('/daily');

        $response->assertStatus(200);
        $response->assertJsonStructure(['difficulty', 'rows', 'cols', 'breaks', 'spark', 'clues', 'name', 'blurb', 'date', 'token']);
        $response->assertJson([
            'difficulty' => 'daily',
            'date' => now('UTC')->toDateString(),
        ]);
    }

    public function test_daily_endpoint_is_deterministic_across_requests(): void
    {
        $a = $this->getJson('/daily')->json();
        $b = $this->getJson('/daily')->json();

        unset($a['token'], $b['token']); // the token embeds an issue timestamp, expected to differ
        $this->assertSame($a, $b);
    }

    public function test_daily_puzzle_difficulty_is_rejected_by_the_puzzle_endpoint(): void
    {
        $response = $this->getJson('/puzzle?difficulty=daily');

        $response->assertStatus(422);
    }

    public function test_hint_endpoint_accepts_the_daily_difficulty(): void
    {
        $daily = $this->getJson('/daily')->json();

        $response = $this->getJson('/hint?'.http_build_query([
            'difficulty' => 'daily',
            'spark' => $daily['spark'],
            'clues' => json_encode($daily['clues']),
        ]));

        $response->assertStatus(200);
        $this->assertContains($response->json('status'), ['forced', 'complete']);
    }

    public function test_submit_daily_score_requires_authentication(): void
    {
        $daily = $this->getJson('/daily')->json();

        $response = $this->postJson('/daily/score', [
            'token' => $daily['token'],
            'shaded' => [],
        ]);

        $response->assertStatus(401);
    }

    public function test_submit_daily_score_accepts_a_correct_board_and_records_a_verified_time(): void
    {
        $user = User::factory()->create();
        $daily = $this->getJson('/daily')->json();

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
        $daily = $this->getJson('/daily')->json();

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
        $daily = $this->getJson('/daily')->json();

        $staleToken = Crypt::encryptString(json_encode([
            'date' => now('UTC')->subDay()->toDateString(),
            'issuedAt' => now('UTC')->subDay()->timestamp,
        ]));

        $response = $this->actingAs($user)->postJson('/daily/score', [
            'token' => $staleToken,
            'shaded' => $this->solveDaily($daily),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('burnfront_daily_scores', 0);
    }

    public function test_submit_daily_score_rejects_a_duplicate_submission_for_the_same_day(): void
    {
        $user = User::factory()->create();
        $daily = $this->getJson('/daily')->json();
        $shaded = $this->solveDaily($daily);

        $this->actingAs($user)->postJson('/daily/score', ['token' => $daily['token'], 'shaded' => $shaded])
            ->assertStatus(200);

        $response = $this->actingAs($user)->postJson('/daily/score', ['token' => $daily['token'], 'shaded' => $shaded]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('burnfront_daily_scores', 1);
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
