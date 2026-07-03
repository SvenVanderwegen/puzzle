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
}
