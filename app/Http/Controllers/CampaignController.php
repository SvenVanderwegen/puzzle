<?php

namespace App\Http\Controllers;

use App\Models\CampaignProfile;
use App\Support\Burnfront\CampaignService;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function puzzle(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user()->id);
        $config = CampaignService::levelConfig(CampaignService::levelForXp($profile->total_xp));

        return response()->json($this->puzzles->generateCampaign($config));
    }

    /**
     * Records a verified board (replayed against the actual engine, same as
     * BurnfrontController::submitEndlessScore()) as one more solved
     * incident at this account's current level, converts it to XP
     * (CampaignService::xpAwarded() — reduced per hint used, zeroed once
     * hints reach the level's firebreak count), and reports whether that
     * XP crossed into a new level. The level scored against is always
     * re-derived from this account's own profile, never trusted from the
     * request, so a client can't claim a level it hasn't earned.
     */
    public function submitScore(Request $request): JsonResponse
    {
        $profile = $this->profile($request->user()->id);
        $level = CampaignService::levelForXp($profile->total_xp);
        $config = CampaignService::levelConfig($level);

        $parsed = Engine::parseSparkAndClues($config['rows'] * $config['cols'], $request->input('spark'), $request->input('clues'));
        if ($parsed === null) {
            return response()->json(['message' => 'Invalid spark or clues.'], 422);
        }
        [$spark, $clues] = $parsed;

        $shaded = Engine::shadedCellsFromRequest($config['rows'] * $config['cols'], $spark, $clues, $request->input('shaded'));
        if ($shaded === null) {
            return response()->json(['message' => 'Invalid shaded cells.'], 422);
        }

        $hintsUsed = $request->input('hints_used');
        if (! is_int($hintsUsed) || $hintsUsed < 0) {
            return response()->json(['message' => 'Invalid hints_used.'], 422);
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
