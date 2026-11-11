<?php

namespace Tests\Unit\Support\Burnfront;

use App\Support\Burnfront\CampaignService;
use PHPUnit\Framework\TestCase;

class CampaignServiceTest extends TestCase
{
    public function test_level_config_resolves_every_level_and_rejects_out_of_range(): void
    {
        for ($level = 1; $level <= CampaignService::TOTAL_LEVELS; $level++) {
            $config = CampaignService::levelConfig($level);
            $this->assertNotNull($config, "level {$level} should resolve");
            $this->assertSame(
                ['label', 'rows', 'cols', 'breaks', 'budgetMs', 'minClues', 'timed', 'chapterKey', 'chapterLabel', 'levelInChapter'],
                array_keys($config)
            );
        }

        $this->assertNull(CampaignService::levelConfig(0));
        $this->assertNull(CampaignService::levelConfig(CampaignService::TOTAL_LEVELS + 1));
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

    public function test_the_rung_is_the_level_until_the_ladder_runs_out(): void
    {
        $this->assertSame(1, CampaignService::rungFor(1));
        $this->assertSame(20, CampaignService::rungFor(20, 5));

        // Past the top: the eight hardest rungs in turn, one step per solve.
        $seen = [];
        for ($solved = 0; $solved < 8; $solved++) {
            $seen[] = CampaignService::rungFor(21, $solved);
        }
        $this->assertSame(range(13, 20), $seen);
        $this->assertSame(13, CampaignService::rungFor(99, 16));
    }
}
