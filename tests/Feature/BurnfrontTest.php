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
}
