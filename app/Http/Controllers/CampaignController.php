<?php

namespace App\Http\Controllers;

use App\Models\CampaignProfile;
use App\Models\GamePlay;
use App\Support\Burnfront\CampaignService;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\NextFire;
use App\Support\Burnfront\PlayerProgress;
use App\Support\Burnfront\Progression;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaign mode: the player's own ladder. Their points decide their level
 * (see Progression), their level decides which kind of fire the campaign
 * gives them (CampaignService::rungFor()) and which rank they hold. Unlike
 * Endless, the board a request is generated/scored against is never a
 * client choice — every method here re-derives it from this account's own
 * CampaignProfile, the same defensive posture BurnfrontController already
 * takes with the daily incident's server-bound start time.
 */
class CampaignController extends Controller
{
    public function __construct(private readonly PuzzleService $puzzles) {}

    /**
     * The campaign page: where this account stands (points, level, rank)
     * and the ladder of ranks, each with its levels marked done, now or
     * later. The last rank has no end, so it lists no levels.
     */
    public function map(Request $request): Response
    {
        $standing = Progression::standing(PlayerProgress::xp($request->user()->id));
        $rules = Progression::rules();

        $ranks = [];
        foreach ($rules['ranks'] as $index => $key) {
            $from = $rules['rankLevels'][$index];
            $next = $rules['rankLevels'][$index + 1] ?? null;
            $ranks[] = [
                'index' => $index,
                'key' => $key,
                'fromLevel' => $from,
                'toLevel' => $next === null ? null : $next - 1,
                'state' => match (true) {
                    $index < $standing['rank'] => 'done',
                    $index === $standing['rank'] => 'now',
                    default => 'later',
                },
                'levels' => $next === null ? [] : array_map(fn (int $level) => [
                    'level' => $level,
                    'state' => match (true) {
                        $level < $standing['level'] => 'reached',
                        $level === $standing['level'] => 'current',
                        default => 'locked',
                    },
                ], range($from, $next - 1)),
            ];
        }

        return Inertia::render('Burnfront/CampaignMap', [
            'standing' => $standing,
            'ranks' => $ranks,
            'next' => NextFire::for($request->user()->id),
            'rules' => $rules,
        ]);
    }

    /**
     * Renders the board for this account's current level — there is no
     * ?level= to pick from, since the level is earned, not chosen.
     */
    public function play(Request $request): Response
    {
        $profile = $this->profile($request->user()->id);
        $level = Progression::standing($profile->total_xp)['level'];
        $rung = CampaignService::rungFor($level, $profile->puzzles_solved);

        return Inertia::render('Burnfront/Play', [
            'mode' => 'campaign',
            // 'level' is the player's own level (what the page shows); the
            // rest describes the kind of board that level gets.
            'levelConfig' => ['level' => $level, ...CampaignService::levelConfig($rung)],
            'authenticated' => true,
            'nextFire' => NextFire::for($request->user()->id, 'campaign'),
            'progressRules' => PlayerProgress::rules(),
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
        $level = Progression::standing($profile->total_xp)['level'];
        $rung = CampaignService::rungFor($level, $profile->puzzles_solved);
        $config = CampaignService::levelConfig($rung);

        $result = $this->puzzles->generateCampaign($config);
        $result['token'] = CampaignService::issueToken($rung, $result['spark'], $result['clues']);
        $result['level'] = $level;

        return response()->json($result);
    }

    /**
     * Records a verified board (replayed against the actual engine, same as
     * BurnfrontController::submitEndlessScore()) as one more solved fire
     * and gives the points for it (PlayerProgress::award()).
     *
     * Both the puzzle (rung/spark/clues) and the hint count come from the
     * signed run token puzzle() issued, never from the request body — a
     * client can neither claim credit for a board it never generated nor
     * under-report hints it actually used (see CampaignService::
     * decodeRun(), BurnfrontController::incrementCampaignHints()). A token
     * can also only ever be redeemed once, so replaying the same solved
     * board can't farm points.
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

        $hintsUsed = (int) Cache::get(CampaignService::hintCacheKey($token), 0);

        $profile = $this->profile($request->user()->id);
        $profile->increment('puzzles_solved');

        // One row per solved fire, like daily and endless already log: this
        // is what the weekly goal counts (PlayerProgress::weekSolved()).
        GamePlay::create([
            'user_id' => $profile->user_id,
            'mode' => 'campaign',
            'difficulty' => 'level-'.$level,
            'date' => null,
            'rows' => $config['rows'],
            'cols' => $config['cols'],
            'breaks' => $config['breaks'],
            'spark' => $spark,
            'clues' => collect($clues)->map(fn ($minute, $cell) => [$cell, $minute])->values()->all(),
            'shaded_cells' => array_keys($shaded),
            'moves' => GamePlay::normalizeMoves($request->input('moves')),
            'time_ms' => null,
            'hints_used' => $hintsUsed,
        ]);

        $reward = PlayerProgress::award($profile->user_id, [
            'rows' => $config['rows'],
            'cols' => $config['cols'],
            'breaks' => $config['breaks'],
            'hintsUsed' => $hintsUsed,
        ]);

        return response()->json([
            'reward' => $reward,
            'xpAwarded' => $reward['total'],
            'level' => $reward['level'],
            'leveledUp' => $reward['leveledUp'],
            'nextFire' => NextFire::for($profile->user_id, 'campaign'),
            'progress' => PlayerProgress::forUser($profile->user_id),
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
