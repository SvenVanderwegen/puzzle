<?php

namespace Tests\Feature;

use Tests\TestCase;

class BurnfrontTest extends TestCase
{
    public function test_the_incident_desk_renders(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('BURNFRONT', false);
    }

    public function test_puzzle_endpoint_generates_a_lookout_incident(): void
    {
        $response = $this->getJson('/puzzle?difficulty=lookout');

        $response->assertStatus(200);
        $response->assertJsonStructure(['difficulty', 'rows', 'cols', 'breaks', 'spark', 'clues']);
        $response->assertJson([
            'difficulty' => 'lookout',
            'rows' => 5,
            'cols' => 5,
            'breaks' => 4,
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
}
