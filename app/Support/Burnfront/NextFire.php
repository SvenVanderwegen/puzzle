<?php

namespace App\Support\Burnfront;

use App\Models\CampaignProfile;

/**
 * Answers "what should this player play next?", for the home screen's
 * "Up next" card and the result screen's primary "Next fire" button.
 *
 * - A signed-in player: their next campaign fire. The campaign has no end
 *   (levels keep counting), so there always is one.
 * - Someone already playing quick fires: another one of the same size, so
 *   the button keeps them in the flow they chose.
 * - A guest (the campaign needs an account): a quick fire.
 *
 * Returns data, not copy: the client turns it into words through its
 * locale files, so this stays translatable.
 */
final class NextFire
{
    /**
     * @return array{kind: 'campaign', href: string, level: int, rank: int, rankKey: string, intoLevel: int, levelSize: int, rows: int, cols: int, breaks: int}|array{kind: 'quick', href: string, difficulty: string, rows: int, cols: int, breaks: int}
     */
    public static function for(?int $userId, ?string $afterMode = null, ?string $afterDifficulty = null): array
    {
        if ($afterMode === 'endless' && $afterDifficulty !== null && isset(PuzzleService::DIFFICULTIES[$afterDifficulty])) {
            return self::quick($afterDifficulty);
        }

        if ($userId !== null) {
            $profile = CampaignProfile::where('user_id', $userId)->first(['total_xp', 'puzzles_solved']);

            return self::campaign((int) ($profile->total_xp ?? 0), (int) ($profile->puzzles_solved ?? 0));
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

    private static function campaign(int $xp, int $puzzlesSolved): array
    {
        $standing = Progression::standing($xp);
        $config = CampaignService::levelConfig(CampaignService::rungFor($standing['level'], $puzzlesSolved));

        return [
            'kind' => 'campaign',
            'href' => '/campaign/play',
            'level' => $standing['level'],
            'rank' => $standing['rank'],
            'rankKey' => $standing['rankKey'],
            'intoLevel' => $standing['intoLevel'],
            'levelSize' => $standing['levelSize'],
            'rows' => $config['rows'],
            'cols' => $config['cols'],
            'breaks' => $config['breaks'],
        ];
    }
}
