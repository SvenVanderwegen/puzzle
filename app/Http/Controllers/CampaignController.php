<?php

namespace App\Http\Controllers;

use App\Models\CampaignProfile;
use App\Support\Burnfront\CampaignService;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaign mode: a fixed 20-level, 5-chapter difficulty ladder a signed-in
 * player climbs by earning XP (see CampaignService). Unlike Endless, the
 * level a request is generated/scored against is never a client choice —
 * every method here re-derives it from this account's own CampaignProfile,
 * the same defensive posture BurnfrontController already takes with the
 * daily incident's server-bound start time.
 */
class CampaignController extends Controller
{
    public function __construct(private readonly PuzzleService $puzzles) {}

    /**
     * The campaign level map: this account's current level/chapter/XP
     * standing plus every chapter's levels annotated reached/current/locked
     * for the path UI.
     */
    public function map(Request $request): Response
    {
        $profile = $this->profile($request->user()->id);
        $progress = CampaignService::progressForXp($profile->total_xp);

        $chapters = collect(CampaignService::chapters())->map(function (array $chapter) use ($progress) {
            $chapter['levels'] = collect($chapter['levels'])->map(fn (int $level) => [
                'level' => $level,
                'label' => CampaignService::levelConfig($level)['label'],
                'state' => match (true) {
                    $level < $progress['level'] => 'reached',
                    $level === $progress['level'] => 'current',
                    default => 'locked',
                },
            ])->all();

            return $chapter;
        })->all();

        return Inertia::render('Burnfront/CampaignMap', [
            'progress' => $progress,
            'chapters' => $chapters,
            'totalLevels' => CampaignService::TOTAL_LEVELS,
        ]);
    }

    /**
     * Renders the board for this account's current level — there is no
     * ?level= to pick from, since the level is earned, not chosen.
     */
    public function play(Request $request): Response
    {
        $profile = $this->profile($request->user()->id);
        $level = CampaignService::levelForXp($profile->total_xp);

        return Inertia::render('Burnfront/Play', [
            'mode' => 'campaign',
            'levelConfig' => CampaignService::levelConfig($level),
            'authenticated' => true,
        ]);
    }

    /**
     * Generates a fresh board at this account's current level and signs it
     * into a run token (CampaignService::issueToken()) that hint(), solve(),
     * and submitScore() all require — the puzzle spec itself is never
     * client-suppliable from here on, only ever recovered from a token this
     * endpoint actually issued.
     */
    public function puzzle(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user()->id);
        $level = CampaignService::levelForXp($profile->total_xp);
        $config = CampaignService::levelConfig($level);

        $result = $this->puzzles->generateCampaign($config);
        $result['token'] = CampaignService::issueToken($level, $result['spark'], $result['clues']);

        return response()->json($result);
    }

    /**
     * Records a verified board (replayed against the actual engine, same as
     * BurnfrontController::submitEndlessScore()) as one more solved
     * incident, converts it to XP (CampaignService::xpAwarded() — reduced
     * per hint used, zeroed once hints reach the level's firebreak count),
     * and reports whether that XP crossed into a new level.
     *
     * Both the puzzle (level/spark/clues) and the hint count come from the
     * signed run token puzzle() issued, never from the request body — a
     * client can neither claim credit for a level/board it never generated
     * nor under-report hints it actually used (see CampaignService::
     * decodeRun(), BurnfrontController::incrementCampaignHints()). A token
     * can also only ever be redeemed for XP once, so replaying the same
     * solved board can't farm XP repeatedly.
     */
    public function submitScore(Request $request): JsonResponse
    {
        $token = $request->input('token');
        $run = CampaignService::decodeRun($token);
        if ($run === null) {
            return response()->json(['message' => 'Invalid or expired token.'], 422);
        }
        $level = $run['level'];
        $spark = $run['spark'];
        $clues = $run['clues'];
        $config = CampaignService::levelConfig($level);

        $shaded = Engine::shadedCellsFromRequest($config['rows'] * $config['cols'], $spark, $clues, $request->input('shaded'));
        if ($shaded === null) {
            return response()->json(['message' => 'Invalid shaded cells.'], 422);
        }

        $puzzle = new Puzzle($config['rows'], $config['cols'], $spark, $clues, $config['breaks']);
        $state = Engine::initialState($puzzle);
        foreach (array_keys($shaded) as $cell) {
            $state[$cell] = Engine::SHADED;
        }
        foreach ($state as $cell => $value) {
            if ($value === Engine::UNKNOWN) {
                $state[$cell] = Engine::OPEN;
            }
        }

        if (! Engine::exactCheck($puzzle, $state)) {
            return response()->json(['message' => "Board doesn't solve the incident."], 422);
        }

        // Atomic: a retried or duplicated request for the same token can
        // never double-redeem it, even under a race.
        if (! Cache::add(CampaignService::redeemedCacheKey($token), true, now()->addSeconds(CampaignService::TOKEN_TTL_SECONDS))) {
            return response()->json(['message' => 'This incident has already been scored.'], 409);
        }

        $hintsUsed = Cache::get(CampaignService::hintCacheKey($token), 0);

        $profile = $this->profile($request->user()->id);
        $xpAwarded = CampaignService::xpAwarded($level, $hintsUsed);
        $profile->total_xp += $xpAwarded;
        $profile->puzzles_solved += 1;
        $profile->save();

        $progress = CampaignService::progressForXp($profile->total_xp);

        return response()->json([
            'xpAwarded' => $xpAwarded,
            'level' => $progress['level'],
            'leveledUp' => $progress['level'] > $level,
            'xpIntoLevel' => $progress['xpIntoLevel'],
            'xpToNextLevel' => $progress['xpToNextLevel'],
            'chapterLabel' => $progress['chapterLabel'],
            'campaignComplete' => $progress['maxed'],
        ]);
    }

    private function profile(int $userId): CampaignProfile
    {
        // firstOrCreate() doesn't hydrate the model from the DB's column
        // defaults after an insert, so total_xp/puzzles_solved must be
        // passed explicitly or a freshly-created profile reads back null.
        return CampaignProfile::firstOrCreate(['user_id' => $userId], ['total_xp' => 0, 'puzzles_solved' => 0]);
    }
}
