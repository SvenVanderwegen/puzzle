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
 * Campaign mode: an endless row of levels, one fire each. The campaign
 * fires a player has contained decide their level, and the level decides
 * which kind of fire comes next (CampaignService::boardFor()). Points and
 * rank are a separate counter, fed by every mode (see Progression). Unlike
 * Endless, the board a request is generated/scored against is never a
 * client choice — every method here re-derives it from this account's own
 * CampaignProfile, the same defensive posture BurnfrontController already
 * takes with the daily incident's server-bound start time.
 */
class CampaignController extends Controller
{
    public function __construct(private readonly PuzzleService $puzzles) {}

    /** How many levels of the row the campaign page shows (three rows of four). */
    private const PATH_SIZE = 12;

    /** ... of which at most this many are already contained ones. */
    private const PATH_BEHIND = 3;

    /**
     * The campaign page: the level this account is on, a stretch of the row
     * of levels around it (a few done, the current one, the next ones, each
     * with its board size), and where the account stands in points and
     * rank. The row has no end; the page just shows the part that matters.
     */
    public function map(Request $request): Response
    {
        $profile = CampaignProfile::where('user_id', $request->user()->id)->first(['total_xp', 'puzzles_solved']);
        $level = CampaignService::levelFor((int) ($profile->puzzles_solved ?? 0));
        $standing = Progression::standing((int) ($profile->total_xp ?? 0));
        $rules = Progression::rules();

        $path = [];
        $from = max(1, $level - self::PATH_BEHIND);
        for ($n = $from; $n < $from + self::PATH_SIZE; $n++) {
            $board = CampaignService::boardFor($n);
            $path[] = [
                'level' => $n,
                'state' => match (true) {
                    $n < $level => 'done',
                    $n === $level => 'current',
                    default => 'locked',
                },
                'rows' => $board['rows'],
                'cols' => $board['cols'],
            ];
        }

        $ranks = [];
        foreach ($rules['ranks'] as $index => $key) {
            $ranks[] = [
                'index' => $index,
                'key' => $key,
                'fromXp' => Progression::xpForLevel($rules['rankLevels'][$index]),
                'state' => match (true) {
                    $index < $standing['rank'] => 'done',
                    $index === $standing['rank'] => 'now',
                    default => 'later',
                },
            ];
        }

        return Inertia::render('Burnfront/CampaignMap', [
            'level' => $level,
            'path' => $path,
            // Where the way up ends: used to colour the levels along the heat ramp.
            'endlessFrom' => CampaignService::firstEndlessLevel(),
            'standing' => $standing,
            'ranks' => $ranks,
            'next' => NextFire::for($request->user()->id),
            'rules' => $rules,
        ]);
    }

    /**
     * Renders the board for the level this account is on. There is no
     * ?level= to pick from: a level opens by containing the one before it.
     */
    public function play(Request $request): Response
    {
        $level = CampaignService::levelFor($this->profile($request->user()->id)->puzzles_solved);

        return Inertia::render('Burnfront/Play', [
            'mode' => 'campaign',
            // 'level' is the number the page shows; the rest describes the
            // kind of board that level gets.
            'levelConfig' => ['level' => $level, ...CampaignService::boardFor($level)],
            'authenticated' => true,
            'nextFire' => NextFire::for($request->user()->id, 'campaign'),
            'progressRules' => PlayerProgress::rules(),
        ]);
    }

    /**
     * Generates a fresh board for the level this account is on and signs it
     * into a run token (CampaignService::issueToken()) that hint(), solve(),
     * and submitScore() all require — the puzzle spec itself is never
     * client-suppliable from here on, only ever recovered from a token this
     * endpoint actually issued.
     */
    public function puzzle(Request $request): JsonResponse
    {
        $level = CampaignService::levelFor($this->profile($request->user()->id)->puzzles_solved);
        $rung = CampaignService::rungFor($level);
        $config = CampaignService::levelConfig($rung);

        $result = $this->puzzles->generateCampaign($config);
        $result['token'] = CampaignService::issueToken($rung, $result['spark'], $result['clues']);
        $result['level'] = $level;

        return response()->json($result);
    }

    /**
     * Records a verified board (replayed against the actual engine, same as
     * BurnfrontController::submitEndlessScore()) as one more contained
     * fire, which opens the next level, and gives the points for it
     * (PlayerProgress::award()).
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
        $rung = $run['level'];
        $spark = $run['spark'];
        $clues = $run['clues'];
        $config = CampaignService::levelConfig($rung);

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
            // The rung (the kind of board), not the level: this is what the
            // games list names the fire by.
            'difficulty' => 'level-'.$rung,
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
            // The level that just opened.
            'level' => CampaignService::levelFor((int) $profile->fresh()->puzzles_solved),
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
