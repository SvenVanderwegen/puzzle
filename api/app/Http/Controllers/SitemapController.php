<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPuzzle;
use Illuminate\Http\Response;

/**
 * /sitemap.xml (WS-15, critique #26): the three indexable pages plus the
 * playable past week of dailies (product.md §1 — past 7 days playable free;
 * future dates 404 and are never listed).
 */
final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $base = $this->baseUrl();
        $today = now('UTC');

        $entries = [
            ['loc' => $base.'/', 'lastmod' => null],
            ['loc' => $base.'/about', 'lastmod' => null],
            ['loc' => $base.'/rules', 'lastmod' => null],
        ];

        $dailies = DailyPuzzle::query()
            ->whereBetween('date', [
                $today->copy()->subDays(7)->toDateString(),
                $today->toDateString(),
            ])
            ->orderBy('date')
            ->get();

        foreach ($dailies as $daily) {
            $entries[] = [
                'loc' => $base.'/daily/'.$daily->date,
                'lastmod' => $daily->published_at->toDateString(),
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($entries as $entry) {
            $xml .= '  <url><loc>'.e($entry['loc']).'</loc>';

            if ($entry['lastmod'] !== null) {
                $xml .= '<lastmod>'.e($entry['lastmod']).'</lastmod>';
            }

            $xml .= "</url>\n";
        }

        $xml .= "</urlset>\n";

        return new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
        ]);
    }

    private function baseUrl(): string
    {
        $url = config('app.url');

        return rtrim(is_string($url) ? $url : '', '/');
    }
}
