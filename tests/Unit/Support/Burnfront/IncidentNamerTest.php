<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\IncidentNamer;
use App\Support\Burnfront\SeededRandom;
use PHPUnit\Framework\TestCase;

class IncidentNamerTest extends TestCase
{
    public function test_generate_is_deterministic_given_the_same_seed(): void
    {
        $a = IncidentNamer::generate(new SeededRandom(42));
        $b = IncidentNamer::generate(new SeededRandom(42));

        $this->assertSame($a, $b);
    }

    public function test_generate_produces_a_fire_or_complex_designation(): void
    {
        $result = IncidentNamer::generate(new SeededRandom(7));

        $this->assertMatchesRegularExpression('/ (Fire|Complex)$/', $result['name']);
    }

    public function test_generate_produces_a_blurb_from_the_known_list(): void
    {
        $result = IncidentNamer::generate(new SeededRandom(99));

        $this->assertContains($result['blurb'], IncidentNamer::BLURBS);
    }
}
