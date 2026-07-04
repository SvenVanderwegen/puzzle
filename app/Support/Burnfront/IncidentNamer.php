<?php

namespace App\Support\Burnfront;

/**
 * Cosmetic labeling only — never touches board generation. Draws a
 * place-word, a designation, and a blurb from small static lists, in that
 * fixed order, so a single seeded `$rand` fully determines both fields
 * reproducibly (see PuzzleService::generateDaily()).
 */
final class IncidentNamer
{
    public const PLACE_WORDS = [
        'Coldwater', 'Deadhorse', 'Widow Creek', 'Six Mile', 'Chalk Bluff',
        'Blackrock', 'Dry Wash', 'Tinder Ridge', 'Salt Fork', 'Hollow Point',
        'Cinder Pass', 'Ashfall', 'Rimrock', 'Split Timber', 'Quail Canyon',
        'Bitter Springs', 'Redshale', 'Windrow', 'Stonebreak', 'Ember Flat',
    ];

    public const DESIGNATIONS = ['Fire', 'Complex'];

    public const BLURBS = [
        'Red flag wind warning, ignition unconfirmed.',
        'Lightning strike, contained on day 3.',
        'Escaped agricultural burn, wind-driven.',
        'Dry lightning bust, multiple starts merged.',
        'Power line fault, confirmed by line crew.',
        'Human-caused, under investigation.',
        'Holdover from a prior season, reburned.',
        'Spot fire jumped the initial line overnight.',
        'Slash pile escape, low humidity that week.',
        'Cause undetermined, case remains open.',
    ];

    /**
     * @return array{name: string, blurb: string}
     */
    public static function generate(callable $rand): array
    {
        $place = self::pick(self::PLACE_WORDS, $rand);
        $designation = self::pick(self::DESIGNATIONS, $rand);
        $blurb = self::pick(self::BLURBS, $rand);

        return [
            'name' => "{$place} {$designation}",
            'blurb' => $blurb,
        ];
    }

    /**
     * @param  list<string>  $items
     */
    private static function pick(array $items, callable $rand): string
    {
        return $items[(int) floor($rand() * count($items))];
    }
}
