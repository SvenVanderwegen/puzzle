<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\CampaignService;
use PHPUnit\Framework\TestCase;

class CampaignServiceTest extends TestCase
{
    public function test_level_config_resolves_every_level_and_rejects_out_of_range(): void
    {
        for ($level = 1; $level <= CampaignService::TOTAL_RUNGS; $level++) {
            $config = CampaignService::levelConfig($level);
            $this->assertNotNull($config, "level {$level} should resolve");
            $this->assertSame(
                ['label', 'rows', 'cols', 'breaks', 'budgetMs', 'minClues', 'timed', 'chapterKey', 'chapterLabel', 'levelInChapter'],
                array_keys($config)
            );
        }

        $this->assertNull(CampaignService::levelConfig(0));
        $this->assertNull(CampaignService::levelConfig(CampaignService::TOTAL_RUNGS + 1));
    }

    public function test_level_config_chapters_land_on_the_shipped_endless_tiers(): void
    {
        // Levels 4/8/12/16/18 are designed to reproduce the exact grid/
        // breaks/clue floor of the already-shipped lookout/crew/hotshot/
        // division/coldcase Endless tiers.
        $lookout = CampaignService::levelConfig(4);
        $this->assertSame(5, $lookout['rows']);
        $this->assertSame(5, $lookout['cols']);
        $this->assertSame(4, $lookout['breaks']);
        $this->assertSame(5, $lookout['minClues']);

        $division = CampaignService::levelConfig(16);
        $this->assertSame(8, $division['rows']);
        $this->assertSame(8, $division['cols']);
        $this->assertSame(17, $division['breaks']);
        $this->assertSame(17, $division['minClues']);

        $coldcase = CampaignService::levelConfig(18);
        $this->assertSame(7, $coldcase['rows']);
        $this->assertSame(7, $coldcase['cols']);
        $this->assertSame(12, $coldcase['breaks']);
        $this->assertSame(6, $coldcase['minClues']);
    }

    public function test_difficulty_ramps_up_within_and_across_chapters(): void
    {
        // Within a chapter, breaks density should never decrease. Clue
        // sparsity (minClues) should never increase either, *except* across
        // a grid-size change — Cold Case (chapter 5) deliberately steps its
        // grid up mid-chapter (7x7 -> 8x8 at L19), and a bigger grid can
        // rightly support more clues while staying just as sparse.
        for ($chapterStart = 1; $chapterStart <= 17; $chapterStart += 4) {
            $prev = CampaignService::levelConfig($chapterStart);
            for ($k = 1; $k < 4; $k++) {
                $next = CampaignService::levelConfig($chapterStart + $k);
                $this->assertGreaterThanOrEqual($prev['breaks'], $next['breaks']);
                if ($prev['rows'] === $next['rows'] && $prev['cols'] === $next['cols']) {
                    $this->assertLessThanOrEqual($prev['minClues'], $next['minClues']);
                }
                $prev = $next;
            }
        }
    }

    public function test_chapters_groups_levels_into_five_chapters_of_four(): void
    {
        $chapters = CampaignService::chapters();

        $this->assertCount(5, $chapters);
        foreach ($chapters as $chapter) {
            $this->assertCount(4, $chapter['levels']);
        }
        $this->assertSame([1, 2, 3, 4], $chapters[0]['levels']);
        $this->assertSame([17, 18, 19, 20], $chapters[4]['levels']);
    }

    public function test_a_level_is_one_past_the_fires_contained(): void
    {
        $this->assertSame(1, CampaignService::levelFor(0));
        $this->assertSame(8, CampaignService::levelFor(7));
        $this->assertSame(1, CampaignService::levelFor(-3));
    }

    public function test_levels_climb_the_rungs_a_few_at_a_time(): void
    {
        // Two levels per rung on the 5 by 5 boards, three on 6 by 6, ...
        $this->assertSame([1, 1, 2, 2, 3, 3, 4, 4], array_map(CampaignService::rungFor(...), range(1, 8)));
        $this->assertSame(5, CampaignService::rungFor(9));
        $this->assertSame(8, CampaignService::rungFor(20));
        $this->assertSame(9, CampaignService::rungFor(21));
        $this->assertSame(13, CampaignService::rungFor(37));
        $this->assertSame(20, CampaignService::rungFor(76));
        $this->assertSame(1, CampaignService::rungFor(0));

        // Never a step back on the way up.
        $prev = 1;
        for ($level = 1; $level < CampaignService::firstEndlessLevel(); $level++) {
            $rung = CampaignService::rungFor($level);
            $this->assertGreaterThanOrEqual($prev, $rung);
            $this->assertLessThanOrEqual($prev + 1, $rung);
            $prev = $rung;
        }
    }

    public function test_levels_never_run_out(): void
    {
        $this->assertSame(77, CampaignService::firstEndlessLevel());

        // Past the way up: the eight hardest rungs in turn, one step per level.
        $this->assertSame(range(13, 20), array_map(CampaignService::rungFor(...), range(77, 84)));
        $this->assertSame(13, CampaignService::rungFor(85));

        foreach ([100, 1234, 1_000_000] as $level) {
            $board = CampaignService::boardFor($level);
            $this->assertGreaterThanOrEqual(7, $board['rows']);
            $this->assertNotSame(CampaignService::rungFor($level), CampaignService::rungFor($level + 1));
        }
    }
}
