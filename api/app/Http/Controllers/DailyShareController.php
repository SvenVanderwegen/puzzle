<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * GET /daily/{date} — the crawler-facing unfurl for a dated Burn Order
 * (WS-10, ADR-0009: Blade owns server-rendered HTML, the SPA owns play).
 *
 * The page carries the incident's Open Graph card (the pipeline's pre-rendered
 * spoiler-free PNG — grid, spark and clues only, never the solution) plus a
 * "Contain it" hand-off into the app. A future incident is sealed until
 * midnight UTC, so future and unpublished dates 404; malformed dates 404.
 *
 * The human hand-off (booting the SPA straight onto this board) is wired when
 * the shell ships from public/ — WS-16/17 deploy — exactly like the /hub
 * redirect seam WS-15 left. Until then the CTA lands on /daily (today).
 */
final class DailyShareController extends Controller
{
    public function show(string $date): View
    {
        if (! self::isUtcDate($date)) {
            abort(404);
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');

        if ($day === null) {
            abort(404); // unreachable after isUtcDate, but pins the type non-null
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();

        // Tomorrow's incident is sealed until it goes live at 00:00 UTC.
        if ($day->greaterThan($today)) {
            abort(404);
        }

        /** @var DailyPuzzle|null $daily */
        $daily = DailyPuzzle::query()->with('puzzle')->find($date);

        if ($daily === null) {
            abort(404);
        }

        /** @var Puzzle $puzzle */
        $puzzle = $daily->puzzle;

        return view('daily.show', [
            // Same inlined critical CSS as the marketing shell (shared layout).
            'boardCss' => LandingController::boardCss(),
            'baseUrl' => self::baseUrl(),
            'date' => $date,
            'incident' => $daily->incident_number,
            'tierLabel' => self::tierLabel($puzzle),
            'ogImage' => self::ogImage($puzzle),
            'isPast' => $day->lessThan($today),
            'weekday' => $day->format('l'),
        ]);
    }

    /** Strict UTC calendar date — rejects malformed and impossible dates. */
    private static function isUtcDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$y, $m, $d] = array_map(intval(...), explode('-', $value));

        return checkdate($m, $d, $y);
    }

    /** e.g. "Hotshot 7×7" — dispatcher-voice, Blade marketing copy (ADR-0022). */
    private static function tierLabel(Puzzle $puzzle): string
    {
        $tier = ucfirst($puzzle->grade_tier);

        return "{$tier} {$puzzle->rows}×{$puzzle->cols}";
    }

    /**
     * The spoiler-free card on the content CDN, alongside the board JSON. Its
     * origin is the host of the configured content URL template, so it tracks
     * whatever bucket the boards themselves are served from.
     */
    private static function ogImage(Puzzle $puzzle): string
    {
        /** @var string $template */
        $template = config('burnfront.content.cdn_url_template');
        $origin = Str::before($template, '/puzzles/');

        return "{$origin}/og/{$puzzle->id}.png";
    }

    private static function baseUrl(): string
    {
        $url = config('app.url');

        return rtrim(is_string($url) ? $url : '', '/');
    }
}
