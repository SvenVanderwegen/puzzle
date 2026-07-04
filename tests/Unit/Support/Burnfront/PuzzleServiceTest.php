<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\PuzzleService;
use PHPUnit\Framework\TestCase;

class PuzzleServiceTest extends TestCase
{
    public function test_generate_daily_is_deterministic_for_the_same_date(): void
    {
        $service = new PuzzleService;

        $a = $service->generateDaily('2026-07-03');
        $b = $service->generateDaily('2026-07-03');

        $this->assertSame($a, $b);
    }

    public function test_generate_daily_differs_across_dates(): void
    {
        $service = new PuzzleService;

        $a = $service->generateDaily('2026-07-03');
        $b = $service->generateDaily('2026-07-04');

        $this->assertNotSame([$a['spark'], $a['clues']], [$b['spark'], $b['clues']]);
    }

    public function test_generate_daily_payload_has_date_name_and_blurb(): void
    {
        $service = new PuzzleService;

        $result = $service->generateDaily('2026-07-03');

        $this->assertSame('2026-07-03', $result['date']);
        $this->assertSame('daily', $result['difficulty']);
        $this->assertIsString($result['name']);
        $this->assertIsString($result['blurb']);
    }

    public function test_tier_config_resolves_known_tiers_and_daily_but_not_unknown(): void
    {
        $this->assertSame(PuzzleService::DIFFICULTIES['lookout'], PuzzleService::tierConfig('lookout'));
        $this->assertNotNull(PuzzleService::tierConfig('daily'));
        $this->assertNull(PuzzleService::tierConfig('arsonist'));
    }
}
