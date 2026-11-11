<?php

namespace App\Support\Burnfront;

use App\Models\CampaignProfile;

/**
 * Answers "what should this player play next?", for the home screen's
 * "Up next" card and the result screen's primary "Next fire" button.
 *
 * - A signed-in player with campaign fires left: their next campaign fire.
 * - Someone already playing quick fires: another one of the same size, so
 *   the button keeps them in the flow they chose.
 * - A guest (the campaign needs an account) or a player who finished the
 *   campaign: a quick fire.
 *
 * Returns data, not copy: the client turns it into words through its
 * locale files, so this stays translatable.
 */
final class NextFire
{
    /**
     * @return array{kind: 'campaign', href: string, level: int, totalLevels: int, solvedLevels: int, rows: int, cols: int, breaks: int, chapterLabel: string}|array{kind: 'quick', href: string, difficulty: string, rows: int, cols: int, breaks: int}
     */
    public static function for(?int $userId, ?string $afterMode = null, ?string $afterDifficulty = null): array
    {
        if ($afterMode === 'endless' && $afterDifficulty !== null && isset(PuzzleService::DIFFICULTIES[$afterDifficulty])) {
            return self::quick($afterDifficulty);
        }

        if ($userId !== null) {
            $totalXp = (int) (CampaignProfile::where('user_id', $userId)->value('total_xp') ?? 0);
            $progress = CampaignService::progressForXp($totalXp);
            if (! $progress['maxed']) {
                return self::campaign($progress['level']);
            }

            return self::quick('division');
        }

        return self::quick((string) config('burnfront.guest_next_tier', 'lookout'));
    }

    /**
     * The one-tap quick fire buttons on the home screen.
     *
     * @return list<array{difficulty: string, rows: int, cols: int, breaks: int, href: string}>
     */
    public static function quickFires(): array
    {
        $out = [];
        foreach ((array) config('burnfront.quick_fires', ['lookout', 'crew', 'division']) as $difficulty) {
            if (isset(PuzzleService::DIFFICULTIES[$difficulty])) {
                $quick = self::quick($difficulty);
                unset($quick['kind']);
                $out[] = $quick;
            }
        }

        return $out;
    }

    /**
     * Quick fires run without a visible clock (relaxed=1): the home promises
     * "No clock. Pick a size." The solve still counts for the weekly goal
     * and level, just not for a personal-best time.
     */
    public static function quickHref(string $difficulty): string
    {
        return '/endless/play?'.http_build_query(['difficulty' => $difficulty, 'relaxed' => 1]);
    }

    private static function quick(string $difficulty): array
    {
        $tier = PuzzleService::DIFFICULTIES[$difficulty] ?? PuzzleService::DIFFICULTIES[PuzzleService::DEFAULT_DIFFICULTY];

        return [
            'kind' => 'quick',
            'href' => self::quickHref($difficulty),
            'difficulty' => $difficulty,
            'rows' => $tier['rows'],
            'cols' => $tier['cols'],
            'breaks' => $tier['breaks'],
        ];
    }

    private static function campaign(int $level): array
    {
        $config = CampaignService::levelConfig($level);

        return [
            'kind' => 'campaign',
            'href' => '/campaign/play',
            'level' => $level,
            'totalLevels' => CampaignService::TOTAL_LEVELS,
            'solvedLevels' => $level - 1,
            'rows' => $config['rows'],
            'cols' => $config['cols'],
            'breaks' => $config['breaks'],
            'chapterLabel' => $config['chapterLabel'],
        ];
    }
}
