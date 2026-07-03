<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyPuzzle;
use App\Models\DailyStat;
use App\Models\Puzzle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * GET /daily/{date} + POST /daily/{date}/start (contracts/openapi.yaml).
 * One endpoint with stats embedded (decisions.md #3); UTC day keys (ADR-0002).
 */
final class DailyController extends Controller
{
    /**
     * Public. 404 for unpublished dates — including every future date, so the
     * calendar horizon never leaks.
     */
    public function show(string $date): JsonResponse
    {
        [$daily, $puzzle] = $this->publishedDaily($date);

        /** @var DailyStat|null $stat */
        $stat = DailyStat::query()->find($daily->date);

        $body = [
            'date' => $daily->date,
            'incident_number' => $daily->incident_number,
            'puzzle_id' => $daily->puzzle_id,
            'grade_tier' => $puzzle->grade_tier,
            'content_url' => $this->contentUrl($puzzle),
            'amnesty' => $daily->amnesty,
            'stats' => [
                'solved_count' => $stat->solved_count ?? 0,
                'p50_ms' => $stat?->p50_ms,
            ],
        ];

        if ((bool) config('burnfront.content.origin_fallback')) {
            // CDN outage fallback (critique #17): serve the board from origin.
            $body['puzzle'] = $puzzle->spec;
        }

        return new JsonResponse($body);
    }

    /**
     * Auth. Stamps the anti-cheat fetch anchor; idempotent, first stamp wins
     * (puzzle_fetches PK). The first stamp also counts the start in the
     * daily_stats aggregates.
     */
    public function start(Request $request, string $date): Response
    {
        [$daily] = $this->publishedDaily($date);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($daily, $user): void {
            $stamped = DB::table('puzzle_fetches')->insertOrIgnore([
                'user_id' => $user->id,
                'puzzle_id' => $daily->puzzle_id,
                'fetched_at' => CarbonImmutable::now('UTC'),
            ]);

            if ($stamped === 1) {
                DailyStat::query()->insertOrIgnore([
                    ['date' => $daily->date, 'solved_count' => 0, 'started_count' => 0],
                ]);

                DailyStat::query()->whereKey($daily->date)->increment('started_count');
            }
        });

        return response()->noContent();
    }

    /**
     * @return array{DailyPuzzle, Puzzle}
     */
    private function publishedDaily(string $date): array
    {
        [$y, $m, $d] = array_pad(array_map(intval(...), explode('-', $date)), 3, 0);

        if (! checkdate($m, $d, $y)) {
            abort(404, 'No incident published for this date.');
        }

        $today = CarbonImmutable::now('UTC')->format('Y-m-d');

        /** @var DailyPuzzle|null $daily */
        $daily = DailyPuzzle::query()->with('puzzle')->find($date);

        if ($daily === null || $daily->date > $today) {
            abort(404, 'No incident published for this date.');
        }

        /** @var Puzzle $puzzle */
        $puzzle = $daily->puzzle;

        return [$daily, $puzzle];
    }

    private function contentUrl(Puzzle $puzzle): string
    {
        /** @var string $template */
        $template = config('burnfront.content.cdn_url_template');

        return strtr($template, [
            '{id}' => $puzzle->id,
            '{version}' => $puzzle->content_version,
        ]);
    }
}
