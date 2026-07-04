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

    public function test_custom_config_builds_a_tier_shaped_config_for_a_valid_grid(): void
    {
        $config = PuzzleService::customConfig(6, 7, 10);

        $this->assertSame(['label', 'rows', 'cols', 'breaks', 'budgetMs', 'minClues', 'timed'], array_keys($config));
        $this->assertSame(6, $config['rows']);
        $this->assertSame(7, $config['cols']);
        $this->assertSame(10, $config['breaks']);
        $this->assertSame(10, $config['minClues']);
        $this->assertTrue($config['timed']);
    }

    public function test_custom_config_rejects_dimensions_outside_bounds(): void
    {
        $this->assertNull(PuzzleService::customConfig(PuzzleService::CUSTOM_MIN_DIM - 1, 6, 4));
        $this->assertNull(PuzzleService::customConfig(6, PuzzleService::CUSTOM_MAX_DIM + 1, 4));
    }

    public function test_custom_config_rejects_a_break_count_outside_bounds(): void
    {
        $this->assertNull(PuzzleService::customConfig(6, 6, PuzzleService::CUSTOM_MIN_BREAKS - 1));
        $this->assertNull(PuzzleService::customConfig(6, 6, PuzzleService::customMaxBreaks(6, 6) + 1));
    }

    public function test_custom_max_breaks_grows_with_grid_size(): void
    {
        $this->assertGreaterThan(PuzzleService::customMaxBreaks(4, 4), PuzzleService::customMaxBreaks(10, 10));
        $this->assertGreaterThanOrEqual(PuzzleService::CUSTOM_MIN_BREAKS, PuzzleService::customMaxBreaks(
            PuzzleService::CUSTOM_MIN_DIM,
            PuzzleService::CUSTOM_MIN_DIM
        ));
    }

    public function test_generate_accepts_a_custom_config_override(): void
    {
        $service = new PuzzleService;
        $config = PuzzleService::customConfig(5, 5, 4);

        $result = $service->generate('custom', $config);

        $this->assertSame('custom', $result['difficulty']);
        $this->assertSame(5, $result['rows']);
        $this->assertSame(5, $result['cols']);
        $this->assertSame(4, $result['breaks']);
    }
}
