<?php

namespace App\Http\Controllers;

use App\Models\DailyIncident;
use App\Models\DailyScore;
use App\Models\EndlessScore;
use App\Models\GamePlay;
use App\Support\Burnfront\CampaignService;
use App\Support\Burnfront\CareerProgress;
use App\Support\Burnfront\DailyRunStore;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\NextFire;
use App\Support\Burnfront\PlayerProgress;
use App\Support\Burnfront\Progression;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

class BurnfrontController extends Controller
{
    public function __construct(private readonly PuzzleService $puzzles) {}

    /**
     * The start screen. Besides the mode menu it carries what the home needs
     * to push past the daily: today's fire (for guests too, since the daily
     * no longer needs an account), what to play next, the quick fire sizes,
     * and the weekly goal / level standing.
     *
     * Deliberately reads state instead of going through daily() — looking
     * at the menu must never bind a daily start time. For the same reason
     * the daily card only gets the board's size, never its clues: a preview
     * of the clues would let a player study the board before their clock
     * starts.
     */
    public function start(Request $request): Response
    {
        $user = $request->user();
        $date = now('UTC')->toDateString();
        $campaignStatus = null;
        $progress = null;

        $incident = $this->todaysIncident($date);
        $dailyStatus = [
            'date' => $date,
            'number' => PlayerProgress::dailyNumber($date),
            'name' => $incident['name'] ?? null,
            'rows' => $incident['rows'] ?? null,
            'cols' => $incident['cols'] ?? null,
            'breaks' => $incident['breaks'] ?? null,
            'alreadyScored' => false,
            'scoreTimeMs' => null,
            'hintsUsed' => null,
            'ranked' => null,
            'streak' => null,
            'solved' => null,
        ];

        if ($user !== null) {
            $existing = DailyScore::where('user_id', $user->id)->whereDate('date', $date)->first();

            $dailyStatus['alreadyScored'] = $existing !== null;
            $dailyStatus['scoreTimeMs'] = $existing?->time_ms;
            $dailyStatus['hintsUsed'] = $existing?->hints_used;
            $dailyStatus['ranked'] = $existing?->ranked;
            $dailyStatus['streak'] = PlayerProgress::streaks($user->id);

            // Read-only, same as dailyStatus above — viewing the menu must
            // never create a CampaignProfile row just because someone looked.
            $progress = PlayerProgress::forUser($user->id);
            $campaignStatus = Progression::standing($progress['xp']);
        } else {
            $guest = DailyRunStore::for($request)->guestResult($date);
            $dailyStatus['alreadyScored'] = $guest !== null;
            $dailyStatus['scoreTimeMs'] = $guest['time_ms'] ?? null;
            $dailyStatus['hintsUsed'] = $guest['hints_used'] ?? null;
            $dailyStatus['ranked'] = $guest === null ? null : false;
        }

        // Once solved, the board is no secret any more: hand over the
        // solved map for the "Today's fire is contained" thumbnail.
        if ($dailyStatus['alreadyScored'] && $incident !== null) {
            $dailyStatus['solved'] = [
                'rows' => $incident['rows'],
                'cols' => $incident['cols'],
                'spark' => $incident['spark'],
                'clues' => $incident['clues'],
                'shaded' => $this->solveDaily($incident),
            ];
        }

        return Inertia::render('Burnfront/Start', [
            'dailyStatus' => $dailyStatus,
            'campaignStatus' => $campaignStatus,
            'upNext' => NextFire::for($user?->id),
            'quickFires' => NextFire::quickFires(),
            'progress' => $progress,
            'progressRules' => PlayerProgress::rules(),
        ]);
    }

    /**
     * The setup screen between the start menu and an endless game: the
     * player picks a difficulty tier here before a board is ever generated.
     */
    public function endlessSetup(Request $request): Response
    {
        return Inertia::render('Burnfront/EndlessSetup', [
            'difficulties' => PuzzleService::DIFFICULTIES,
            'customBounds' => [
                'minDim' => PuzzleService::CUSTOM_MIN_DIM,
                'maxDim' => PuzzleService::CUSTOM_MAX_DIM,
                'minBreaks' => PuzzleService::CUSTOM_MIN_BREAKS,
                'breaksRatio' => PuzzleService::CUSTOM_BREAKS_RATIO,
            ],
            'bestTimes' => $this->endlessBestTimes($request->user()?->id),
        ]);
    }

    /**
     * For 'custom' this also carries the player's requested rows/cols/breaks
     * (validated against PuzzleService::customConfig()) — everything else
     * about the flow (newGame(), requestHint() in Play.vue) reads the grid
     * back out of the 'custom' entry this injects into `difficulties` rather
     * than needing a separate prop.
     */
    public function endlessPlay(Request $request): Response
    {
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();

        $authenticated = $request->user() !== null;
        // Quick fire from the home screen: same tiers, no visible clock.
        $relaxed = $request->boolean('relaxed');

        if ($difficulty === 'custom') {
            $config = $this->resolveCustomConfig($request);
            if ($config !== null) {
                return Inertia::render('Burnfront/Play', [
                    'mode' => 'endless',
                    'difficulties' => PuzzleService::DIFFICULTIES + ['custom' => $config],
                    'difficulty' => 'custom',
                    'authenticated' => $authenticated,
                    'relaxed' => $relaxed,
                    'nextFire' => NextFire::for($request->user()?->id),
                    'progressRules' => PlayerProgress::rules(),
                ]);
            }
            $difficulty = PuzzleService::DEFAULT_DIFFICULTY;
        } elseif (! array_key_exists($difficulty, PuzzleService::DIFFICULTIES)) {
            $difficulty = PuzzleService::DEFAULT_DIFFICULTY;
        }

        return Inertia::render('Burnfront/Play', [
            'mode' => 'endless',
            'difficulties' => PuzzleService::DIFFICULTIES,
            'difficulty' => $difficulty,
            'authenticated' => $authenticated,
            'relaxed' => $relaxed,
            'nextFire' => NextFire::for($request->user()?->id, 'endless', $difficulty),
            'progressRules' => PlayerProgress::rules(),
        ]);
    }

    /**
     * Open to guests: the daily is the front door of the game, so it must
     * not start with a sign-in wall. An account only adds the leaderboard
     * and the streak (see DailyRunStore for how a guest run is tracked and
     * later saved to an account).
     */
    public function dailyPlay(Request $request): Response
    {
        return Inertia::render('Burnfront/Play', [
            'mode' => 'daily',
            'authenticated' => $request->user() !== null,
            'nextFire' => NextFire::for($request->user()?->id),
            'progressRules' => PlayerProgress::rules(),
        ]);
    }

    public function howTo(): Response
    {
        return Inertia::render('Burnfront/HowTo');
    }

    /**
     * The first-time lesson: a fixed 4x4 board walked through in three
     * steps. Everything runs in the browser (resources/js/lib/burnfront-lesson.js),
     * so no account and no puzzle request are needed.
     */
    public function lesson(): Response
    {
        return Inertia::render('Burnfront/Lesson');
    }

    public function puzzle(Request $request): JsonResponse
    {
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();

        if ($difficulty === 'custom') {
            $config = $this->resolveCustomConfig($request);
            if ($config === null) {
                return response()->json(['message' => 'Invalid custom grid.'], 422);
            }

            return response()->json($this->puzzles->generateCustom($config));
        }

        if (! array_key_exists($difficulty, PuzzleService::DIFFICULTIES)) {
            return response()->json(['message' => "Unknown difficulty [{$difficulty}]."], 422);
        }

        return response()->json($this->puzzles->generate($difficulty));
    }

    /**
     * Today's shared incident, cached per UTC date so every request that day
     * gets the byte-identical board (Engine::generate()'s clue-stripping
     * loop is wall-clock-bounded, not iteration-bounded, so re-running it
     * for the "same" seed isn't guaranteed to converge at the same point —
     * caching closes that gap). Open to guests: a guest's start time is bound
     * in their session, and when they sign in the earlier of the guest and
     * account start times wins (DailyRunStore::adoptGuestRun()), so seeing
     * the board as a guest never buys a fresh clock in the same browser.
     * This binds (idempotently — first call wins) the player's start time for today,
     * which submitDailyScore() later measures against instead of trusting
     * anything the client reports: refetching /daily can never reset it,
     * so a player can't "restart the clock" right before submitting a
     * board they already knew the answer to.
     *
     * generateDaily() is passed this deployment's APP_KEY as its secret
     * (see PuzzleService::generateDaily()'s docblock): without it, anyone
     * with this source tree could compute any future date's board offline,
     * arbitrarily far ahead of it going live, since a date string alone
     * isn't a secret.
     */
    public function daily(Request $request): JsonResponse
    {
        $date = now('UTC')->toDateString();

        $payload = Cache::remember(
            $this->dailyCacheKey($date),
            now('UTC')->endOfDay(),
            fn () => $this->puzzles->generateDaily($date, (string) config('app.key'))
        );

        $this->persistDailyIncident($date, $payload);

        $store = DailyRunStore::for($request);
        $store->bindStart($date);

        if ($request->user() !== null) {
            $existing = DailyScore::where('user_id', $request->user()->id)->whereDate('date', $date)->first();
            $scored = $existing === null ? null : ['time_ms' => $existing->time_ms, 'hints_used' => $existing->hints_used];
        } else {
            $scored = $store->guestResult($date);
        }

        $payload['number'] = PlayerProgress::dailyNumber($date);
        $payload['guest'] = $request->user() === null;
        $payload['alreadyScored'] = $scored !== null;
        $payload['scoreTimeMs'] = $scored['time_ms'] ?? null;
        if ($scored !== null) {
            $payload['solution'] = $this->solveDaily($payload);
            $payload['hintsUsed'] = $scored['hints_used'];
        }
        $payload['token'] = Crypt::encryptString(json_encode(['date' => $date]));

        return response()->json($payload);
    }

    /**
     * Records a server-verified completion time for today's daily incident.
     * Never trusts the client's reported elapsed time or board: elapsed
     * time is measured from the player's bound start (see daily() /
     * DailyRunStore::bindStart()), not from anything the client sends, and the
     * submitted cells are replayed against the actual engine before any
     * time is recorded.
     */
    public function submitDailyScore(Request $request): JsonResponse
    {
        $tokenRaw = $request->string('token')->toString();
        $shadedRaw = $request->input('shaded');

        if ($tokenRaw === '' || ! is_array($shadedRaw)) {
            return response()->json(['message' => 'Invalid submission.'], 422);
        }

        try {
            $token = json_decode(Crypt::decryptString($tokenRaw), true);
        } catch (DecryptException) {
            return response()->json(['message' => 'Invalid token.'], 422);
        }

        if (! is_array($token) || ! isset($token['date']) || ! is_string($token['date'])) {
            return response()->json(['message' => 'Invalid token.'], 422);
        }

        $date = now('UTC')->toDateString();
        if ($token['date'] !== $date) {
            return response()->json(['message' => "Not today's incident."], 422);
        }

        $store = DailyRunStore::for($request);

        if ($store->isVoided($date)) {
            return response()->json(['message' => 'This run was voided after revealing the solution.'], 422);
        }

        $startedAt = $store->startedAt($date);
        if ($startedAt === null) {
            return response()->json(['message' => "Load today's incident before submitting."], 422);
        }

        $puzzlePayload = Cache::get($this->dailyCacheKey($date));
        if ($puzzlePayload === null) {
            return response()->json(['message' => 'Incident expired, refresh.'], 422);
        }

        $clues = [];
        foreach ($puzzlePayload['clues'] as $pair) {
            $clues[$pair[0]] = $pair[1];
        }
        $spark = $puzzlePayload['spark'];

        $shaded = $this->shadedCellsFromRequest($puzzlePayload, $spark, $clues, $shadedRaw);
        if ($shaded === null) {
            return response()->json(['message' => 'Invalid shaded cells.'], 422);
        }

        $puzzle = new Puzzle($puzzlePayload['rows'], $puzzlePayload['cols'], $spark, $clues, $puzzlePayload['breaks']);
        $state = Engine::initialState($puzzle);
        foreach (array_keys($shaded) as $cell) {
            $state[$cell] = Engine::SHADED;
        }
        // Every cell not explicitly shaded burns per the game's own rules —
        // fill the rest of the state so exactCheck() sees a complete board
        // instead of bailing out on cells initialState() left UNKNOWN.
        foreach ($state as $cell => $value) {
            if ($value === Engine::UNKNOWN) {
                $state[$cell] = Engine::OPEN;
            }
        }

        if (! Engine::exactCheck($puzzle, $state)) {
            return response()->json(['message' => "Board doesn't solve the incident."], 422);
        }

        $timeMs = max(0, now('UTC')->valueOf() - $startedAt * 1000);
        $hintsUsed = $store->hints($date);

        if ($store->isGuest()) {
            // Verified, but there's no account to put it on yet: keep it in
            // the session until they sign in (DailyRunStore::adoptGuestRun()).
            if ($store->guestResult($date) !== null) {
                return response()->json(['message' => "Already on today's board."], 409);
            }
            $store->saveGuestResult($date, [
                'time_ms' => $timeMs,
                'hints_used' => $hintsUsed,
                'shaded' => array_keys($shaded),
                'moves' => $this->normalizeMoves($request->input('moves')),
            ]);

            return response()->json(['time_ms' => $timeMs, 'rank' => null, 'hints_used' => $hintsUsed, 'guest' => true]);
        }

        $userId = $request->user()->id;

        if (DailyScore::where('user_id', $userId)->whereDate('date', $date)->exists()) {
            return response()->json(['message' => "Already on today's board."], 409);
        }

        try {
            $score = DailyScore::create([
                'user_id' => $userId,
                'date' => $date,
                'time_ms' => $timeMs,
                'hints_used' => $hintsUsed,
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => "Already on today's board."], 409);
        }

        GamePlay::create([
            'user_id' => $userId,
            'mode' => 'daily',
            'difficulty' => null,
            'date' => $date,
            'rows' => $puzzlePayload['rows'],
            'cols' => $puzzlePayload['cols'],
            'breaks' => $puzzlePayload['breaks'],
            'spark' => $spark,
            'clues' => $puzzlePayload['clues'],
            'shaded_cells' => array_keys($shaded),
            'moves' => $this->normalizeMoves($request->input('moves')),
            'time_ms' => $timeMs,
            'hints_used' => $hintsUsed,
        ]);

        $rank = DailyScore::whereDate('date', $date)->where('ranked', true)->where('time_ms', '<', $score->time_ms)->count() + 1;

        $reward = PlayerProgress::award($userId, [
            'rows' => $puzzlePayload['rows'],
            'cols' => $puzzlePayload['cols'],
            'breaks' => $puzzlePayload['breaks'],
            'daily' => true,
            'hintsUsed' => $hintsUsed,
        ]);

        return response()->json([
            'time_ms' => $score->time_ms,
            'rank' => $rank,
            'hints_used' => $score->hints_used,
            'reward' => $reward,
            'progress' => PlayerProgress::forUser($userId),
        ]);
    }

    /**
     * @return JsonResponse list of {rank, name, time_ms, hints_used} for the
     *                      given (or today's) date's fastest verified
     *                      completions. hints_used === 0 marks a "clean"
     *                      reconstruction — no forced deductions borrowed
     *                      from the incident desk.
     */
    public function dailyLeaderboard(Request $request): JsonResponse
    {
        $date = $request->string('date', now('UTC')->toDateString())->toString();

        $scores = DailyScore::whereDate('date', $date)
            ->where('ranked', true)
            ->orderBy('time_ms')
            ->limit(20)
            ->with('user:id,name')
            ->get();

        $entries = $scores->values()->map(fn (DailyScore $score, int $i) => [
            'rank' => $i + 1,
            'name' => $score->user->name,
            'time_ms' => $score->time_ms,
            'hints_used' => $score->hints_used,
        ]);

        return response()->json(['date' => $date, 'entries' => $entries]);
    }

    /**
     * This account's case history: every past daily incident it holds a
     * verified time for (most recent first), plus its current/best streak.
     * Names/blurbs come from the persisted DailyIncident row rather than
     * re-running the generator, which the streak/leaderboard math never
     * needs to touch.
     */
    public function dailyHistory(Request $request): Response
    {
        $userId = $request->user()->id;

        $scores = DailyScore::where('user_id', $userId)
            ->orderByDesc('date')
            ->limit(90)
            ->get();

        $dateStrings = $scores->pluck('date')->map(fn ($date) => $date->toDateString());

        // whereDate() range, not whereIn('date', ...): the 'date' cast
        // stores a full datetime string, so exact-match membership checks
        // against Y-m-d strings would never hit (see persistDailyIncident()).
        $incidents = $dateStrings->isEmpty()
            ? collect()
            : DailyIncident::whereDate('date', '>=', $dateStrings->min())
                ->whereDate('date', '<=', $dateStrings->max())
                ->get()
                ->keyBy(fn (DailyIncident $incident) => $incident->date->toDateString());

        $entries = $scores->map(function (DailyScore $score) use ($incidents) {
            $date = $score->date->toDateString();
            $incident = $incidents->get($date);

            return [
                'date' => $date,
                'name' => $incident?->name,
                'blurb' => $incident?->blurb,
                'time_ms' => $score->time_ms,
                'hints_used' => $score->hints_used,
                // False for a score recorded before incidents were persisted
                // (see persistDailyIncident()) — there's nothing to replay,
                // since regenerating the incident later isn't guaranteed to
                // reproduce the exact same clue set the player actually saw
                // (Engine::generate()'s clue-stripping search is wall-clock-
                // bounded, not seed-deterministic).
                'replayable' => $incident !== null,
            ];
        })->values();

        return Inertia::render('Burnfront/DailyHistory', [
            'entries' => $entries,
            'streak' => PlayerProgress::streaks($userId),
        ]);
    }

    /**
     * A read-only replay of one of this account's past daily incidents:
     * the board, its (rederived) solution, and how this account did on it.
     * Only reachable for a date this account actually has a verified score
     * for — this is a case-file review, not a way to preview an unsolved
     * (or someone else's) incident.
     */
    public function dailyHistoryPlay(Request $request): Response|JsonResponse
    {
        $date = $request->string('date', '')->toString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['message' => 'Invalid date.'], 422);
        }

        $userId = $request->user()->id;
        $score = DailyScore::where('user_id', $userId)->whereDate('date', $date)->first();
        $incident = DailyIncident::whereDate('date', $date)->first();
        if ($score === null || $incident === null) {
            return response()->json(['message' => 'No case on file for that date.'], 404);
        }

        $payload = [
            'rows' => $incident->rows,
            'cols' => $incident->cols,
            'breaks' => $incident->breaks,
            'spark' => $incident->spark,
            'clues' => $incident->clues,
            'name' => $incident->name,
            'blurb' => $incident->blurb,
        ];
        $payload['solution'] = $this->solveDaily($payload);
        $payload['date'] = $date;
        $payload['timeMs'] = $score->time_ms;
        $payload['hintsUsed'] = $score->hints_used;

        return Inertia::render('Burnfront/Play', [
            'mode' => 'archive',
            'archivePuzzle' => $payload,
        ]);
    }

    /**
     * Records a verified board (replayed against the actual engine, same as
     * submitDailyScore()) as one more solved incident for this account's
     * running best on a named endless tier — 'custom' grids are rejected
     * since there's no fixed identity to keep a running record against.
     * Untimed tiers (Cold Case) are accepted too, but only ever bump
     * solved_count: time_ms is neither read nor required for them, and
     * best_time_ms stays null forever, since there's no clock to keep a
     * best against. Unlike the daily incident, endless play has no
     * server-bound start time to measure from, so a timed tier's time_ms is
     * trusted from the client: this is a personal-best record, not a
     * competitive leaderboard, and the board itself is still independently
     * verified before any time is recorded.
     */
    public function submitEndlessScore(Request $request): JsonResponse
    {
        $difficulty = $request->string('difficulty', '')->toString();
        if (! array_key_exists($difficulty, PuzzleService::DIFFICULTIES)) {
            return response()->json(['message' => "Unknown difficulty [{$difficulty}]."], 422);
        }
        // A relaxed quick fire has no clock on screen, so there's no time to
        // keep a best against: it's recorded like an untimed tier.
        $timed = PuzzleService::DIFFICULTIES[$difficulty]['timed'] && ! $request->boolean('relaxed');

        $parsed = $this->parsePuzzleConfig($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        [$config, $spark, $clues] = $parsed;

        $shaded = $this->shadedCellsFromRequest($config, $spark, $clues, $request->input('shaded'));
        if ($shaded === null) {
            return response()->json(['message' => 'Invalid shaded cells.'], 422);
        }

        $timeMsRaw = null;
        if ($timed) {
            $timeMsRaw = $request->input('time_ms');
            if (! is_int($timeMsRaw) || $timeMsRaw < 0) {
                return response()->json(['message' => 'Invalid time.'], 422);
            }
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

        $userId = $request->user()->id;

        $record = EndlessScore::firstOrNew([
            'user_id' => $userId,
            'difficulty' => $difficulty,
        ]);
        $record->solved_count = ($record->solved_count ?? 0) + 1;
        $improved = false;
        if ($timed) {
            $improved = $record->best_time_ms === null || $timeMsRaw < $record->best_time_ms;
            if ($improved) {
                $record->best_time_ms = $timeMsRaw;
            }
        }
        $record->last_solved_at = now();
        $record->save();

        GamePlay::create([
            'user_id' => $userId,
            'mode' => 'endless',
            'difficulty' => $difficulty,
            'date' => null,
            'rows' => $config['rows'],
            'cols' => $config['cols'],
            'breaks' => $config['breaks'],
            'spark' => $spark,
            'clues' => collect($clues)->map(fn ($minute, $cell) => [$cell, $minute])->values()->all(),
            'shaded_cells' => array_keys($shaded),
            'moves' => $this->normalizeMoves($request->input('moves')),
            'time_ms' => $timeMsRaw,
            'hints_used' => 0,
        ]);

        // Hints on a practice board are not counted by the server, so
        // there is no "no hints" bonus here: just the fire itself.
        $reward = PlayerProgress::award($userId, [
            'rows' => $config['rows'],
            'cols' => $config['cols'],
            'breaks' => $config['breaks'],
            'hintsUsed' => null,
        ]);

        return response()->json([
            'solved_count' => $record->solved_count,
            'best_time_ms' => $record->best_time_ms,
            'improved' => $improved,
            'reward' => $reward,
            'progress' => PlayerProgress::forUser($userId),
        ]);
    }

    /**
     * This account's running record across every named endless tier: how
     * many incidents it's closed and its fastest verified board, per tier.
     * Every tier is listed even with no record yet, so the page can show a
     * consistent "not yet attempted" row instead of omitting it.
     */
    public function gameHistory(Request $request): Response
    {
        $userId = $request->user()->id;
        $best = $this->endlessBestTimes($userId);

        $tiers = collect(PuzzleService::DIFFICULTIES)->map(function (array $config, string $key) use ($best) {
            return [
                'difficulty' => $key,
                'label' => $config['label'],
                'timed' => $config['timed'],
                'solvedCount' => $best[$key]['solvedCount'] ?? 0,
                'bestTimeMs' => $best[$key]['bestTimeMs'] ?? null,
            ];
        })->values();

        return Inertia::render('Burnfront/GameHistory', [
            'tiers' => $tiers,
            'career' => $this->computeCareer($userId),
        ]);
    }

    /**
     * This account's raw play log, most recent first: every GamePlay row on
     * record regardless of whether it improved a tier's best time — unlike
     * GameHistory's per-tier running bests, this is every individual case
     * filed, each one a candidate for a move-by-move replay(). Capped at the
     * 100 most recent the same way dailyHistory() caps at 90 days: this is a
     * recent case log, not a full archive browser.
     */
    public function replays(Request $request): Response
    {
        $plays = GamePlay::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (GamePlay $play) => $this->replaySummary($play))
            ->values();

        return Inertia::render('Burnfront/Replays', [
            'plays' => $plays,
        ]);
    }

    /**
     * A move-by-move replay of one of this account's own recorded games: the
     * exact board it was played on plus its full action log (see GamePlay's
     * class doc — moves are unverified client telemetry, replayed purely for
     * display on the frontend, never against the engine here). Route-model
     * bound, but ownership is still checked explicitly — implicit binding
     * alone would happily hand back another account's board and move log.
     */
    public function replay(Request $request, GamePlay $gamePlay): Response|JsonResponse
    {
        if ($gamePlay->user_id !== $request->user()->id) {
            return response()->json(['message' => 'No case on file.'], 404);
        }

        return Inertia::render('Burnfront/Replay', [
            'play' => [
                ...$this->replaySummary($gamePlay),
                'spark' => $gamePlay->spark,
                'clues' => $gamePlay->clues,
                'shadedCells' => $gamePlay->shaded_cells,
                'moves' => $gamePlay->moves,
            ],
        ]);
    }

    /**
     * @return array{id: int, mode: string, difficulty: ?string, difficultyLabel: ?string, date: ?string, rows: int, cols: int, breaks: int, timeMs: ?int, hintsUsed: int, moveCount: int, playedAt: string}
     */
    private function replaySummary(GamePlay $play): array
    {
        return [
            'id' => $play->id,
            'mode' => $play->mode,
            'difficulty' => $play->difficulty,
            'difficultyLabel' => match ($play->mode) {
                'endless' => PuzzleService::DIFFICULTIES[$play->difficulty]['label'] ?? $play->difficulty,
                'campaign' => CampaignService::levelConfig((int) substr((string) $play->difficulty, 6))['label'] ?? 'Campaign',
                default => null,
            },
            'date' => $play->date?->toDateString(),
            'rows' => $play->rows,
            'cols' => $play->cols,
            'breaks' => $play->breaks,
            'timeMs' => $play->time_ms,
            'hintsUsed' => $play->hints_used,
            'moveCount' => count($play->moves),
            'playedAt' => $play->created_at->toIso8601String(),
        ];
    }

    /**
     * The top of Game History: where the player stands (points, level,
     * rank, see Progression) and a small set of milestone badges read off
     * totals DailyScore/EndlessScore already record (see CareerProgress).
     *
     * @return array{totalSolved: int, standing: array<string, mixed>, badges: list<array{key: string, label: string, description: string, earned: bool}>}
     */
    private function computeCareer(int $userId): array
    {
        $totalSolved = PlayerProgress::totalSolved($userId);

        $facts = [
            'totalSolved' => $totalSolved,
            'bestStreak' => PlayerProgress::streaks($userId)['best'],
            'hasCleanDaily' => DailyScore::where('user_id', $userId)->where('hints_used', 0)->exists(),
            'hasColdCase' => EndlessScore::where('user_id', $userId)->where('difficulty', 'coldcase')->exists(),
        ];

        return [
            'totalSolved' => $totalSolved,
            'standing' => Progression::standing(PlayerProgress::xp($userId)),
            'badges' => CareerProgress::badges($facts),
        ];
    }

    /**
     * Reads rows/cols/breaks off the request's query string for the
     * 'custom' difficulty and hands them to PuzzleService::customConfig()
     * for bounds validation — null if any is missing, non-numeric, or out
     * of bounds. Query values arrive as strings, so ctype_digit() guards
     * against non-integer input (negative numbers, floats) before casting.
     *
     * @return array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}|null
     */
    private function resolveCustomConfig(Request $request): ?array
    {
        foreach (['rows', 'cols', 'breaks'] as $key) {
            $raw = $request->query($key);
            if (! is_string($raw) || ! ctype_digit($raw)) {
                return null;
            }
        }

        return PuzzleService::customConfig(
            (int) $request->query('rows'),
            (int) $request->query('cols'),
            (int) $request->query('breaks'),
        );
    }

    /**
     * Today's fire if it already exists (cached payload or persisted row),
     * without generating it: the generator can take seconds, too slow for
     * the home screen. Null until the first /daily request of the day.
     *
     * @return array{rows: int, cols: int, breaks: int, spark: int, clues: list<array{0: int, 1: int}>, name: ?string, blurb: ?string}|null
     */
    private function todaysIncident(string $date): ?array
    {
        $cached = Cache::get($this->dailyCacheKey($date));
        if (is_array($cached)) {
            return $cached;
        }

        $incident = DailyIncident::whereDate('date', $date)->first();
        if ($incident === null) {
            return null;
        }

        return [
            'rows' => $incident->rows,
            'cols' => $incident->cols,
            'breaks' => $incident->breaks,
            'spark' => $incident->spark,
            'clues' => $incident->clues,
            'name' => $incident->name,
            'blurb' => $incident->blurb,
        ];
    }

    private function dailyCacheKey(string $date): string
    {
        return "burnfront:daily:v1:{$date}";
    }

    /**
     * Whether a client-supplied spark/clues pair actually matches today's
     * real persisted incident — the gate on DailyRunStore::incrementHints().
     * Key order in $clues doesn't necessarily match the incident's stored
     * pair order, so both are normalized (ksort) before comparing.
     */
    private function matchesTodaysDailyIncident(int $spark, array $clues): bool
    {
        $incident = DailyIncident::whereDate('date', now('UTC')->toDateString())->first();
        if ($incident === null || $spark !== $incident->spark) {
            return false;
        }

        $incidentClues = [];
        foreach ($incident->clues as [$cell, $minute]) {
            $incidentClues[$cell] = $minute;
        }

        ksort($clues);
        ksort($incidentClues);

        return $clues === $incidentClues;
    }

    /**
     * Persists the "pure" incident shape (grid, spark, clues, name, blurb)
     * for a date the first time it's generated — idempotent, since every
     * later /daily request for the same date hits the same cached payload
     * anyway. This is what makes case history cheap to display later: the
     * generator's uniqueness search never has to run again for a past date,
     * only the (fast) deduction solver does.
     */
    private function persistDailyIncident(string $date, array $payload): void
    {
        // whereDate(), not where('date', $date): the 'date' cast stores a
        // full datetime string, so a plain equality check against the
        // Y-m-d $date argument would never match an existing row (see
        // DailyScore's own lookups elsewhere in this class, which use the
        // same whereDate() pattern for the same reason).
        if (DailyIncident::whereDate('date', $date)->exists()) {
            return;
        }

        try {
            DailyIncident::create([
                'date' => $date,
                'rows' => $payload['rows'],
                'cols' => $payload['cols'],
                'breaks' => $payload['breaks'],
                'spark' => $payload['spark'],
                'clues' => $payload['clues'],
                'name' => $payload['name'],
                'blurb' => $payload['blurb'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request already persisted today's incident first.
        }
    }

    /**
     * @return array{cell: int, ...}|null the shaded-cell membership map (a
     *                                    cell => true dictionary) for a
     *                                    raw request array, or null if any
     *                                    entry is out of range, a clue, the
     *                                    spark, or repeated.
     */
    private function shadedCellsFromRequest(array $config, int $spark, array $clues, mixed $shadedRaw): ?array
    {
        return Engine::shadedCellsFromRequest($config['rows'] * $config['cols'], $spark, $clues, $shadedRaw);
    }

    /**
     * The move log is unverified client-reported telemetry kept only for
     * review/replay (GamePlay::moves) — it's never replayed against the
     * engine and never affects scoring, so this only guards against a
     * pathological client bloating storage with an oversized array. Entry
     * count alone doesn't bound this — a handful of huge entries would slip
     * through — so this also stops once the encoded payload crosses
     * MAX_MOVES_BYTES, and drops any single entry that isn't JSON-encodable
     * at all rather than letting it poison the whole 'moves' column on save.
     *
     * @return list<mixed>
     */
    private function normalizeMoves(mixed $movesRaw): array
    {
        return GamePlay::normalizeMoves($movesRaw);
    }

    /**
     * @return array<string, array{solvedCount: int, bestTimeMs: int|null}>
     *                                                                      empty if $userId is null (guest)
     */
    private function endlessBestTimes(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        return EndlessScore::where('user_id', $userId)->get()
            ->mapWithKeys(fn (EndlessScore $score) => [
                $score->difficulty => [
                    'solvedCount' => $score->solved_count,
                    'bestTimeMs' => $score->best_time_ms,
                ],
            ])->all();
    }

    /**
     * The daily incident is provably solvable by pure deduction (see
     * Engine::generate()'s minimal-irredundant-clues loop), so once an
     * account has already posted a verified time, the firebreak placement
     * can be recomputed straight from the clues rather than needing to have
     * been stored anywhere — there's exactly one, and this rederives it.
     *
     * @return list<int> the shaded (firebreak) cell indices
     */
    private function solveDaily(array $payload): array
    {
        $clues = [];
        foreach ($payload['clues'] as [$cell, $minute]) {
            $clues[$cell] = $minute;
        }

        $puzzle = new Puzzle($payload['rows'], $payload['cols'], $payload['spark'], $clues, $payload['breaks']);
        $state = Engine::deductionSolve($puzzle);

        $shaded = [];
        foreach ($state as $cell => $value) {
            if ($value === Engine::SHADED) {
                $shaded[] = $cell;
            }
        }

        return $shaded;
    }

    /**
     * One step of pure deduction for the board the client is holding: the
     * incident (spark + clues, as handed out by puzzle()) plus whichever
     * cells the player has already committed as firebreaks. Rows, cols and
     * the break target are taken from the difficulty tier, not the request,
     * so a client can't puppet the solver into working an arbitrarily large
     * grid. `open` cells (the player's "clear-ground" dots) are folded into
     * the state as committed OPEN alongside `shaded`.
     *
     * Only ever surfaces a forced *firebreak* — the hint system dropped
     * "stays clear" hints as a source of player confusion, so any forced-OPEN
     * step found along the way is applied to a scratch copy of the state and
     * the search silently continues from there, without telling the client.
     * That scratch state is thrown away either way: nothing this endpoint
     * concludes is trusted without being independently re-derived next call,
     * from whatever the client has actually committed by then.
     *
     * A `contradiction` (the committed shaded/open cells already can't lead
     * to a valid board) also reports which of the committed firebreaks are
     * individually to blame, so the client can flag them instead of just
     * saying "something's wrong" — see Engine::misplacedShaded().
     *
     * /hint is open to guests for every mode, the daily included, since the
     * daily is playable without an account. A guest's daily hints are
     * charged to their session (DailyRunStore) and follow them onto their
     * account if they sign in. A hint drawn from a second, cookie-less
     * session is not charged to anyone's account — that is the same reach a
     * free second account always had, so it adds no new hole, but it does
     * mean the "no hints" mark is honour-based against a determined cheat.
     */
    public function hint(Request $request): JsonResponse
    {
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();

        $parsed = $this->parsePuzzleConfig($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        [$config, $spark, $clues] = $parsed;
        $cellCount = $config['rows'] * $config['cols'];
        $invalid = fn (string $message) => response()->json(['message' => $message], 422);

        $shadedRaw = json_decode((string) $request->query('shaded', '[]'), true);
        if (! is_array($shadedRaw) || count($shadedRaw) > $cellCount) {
            return $invalid('Invalid shaded cells.');
        }
        $shaded = [];
        foreach ($shadedRaw as $cell) {
            if (
                ! is_int($cell) || $cell < 0 || $cell >= $cellCount || $cell === $spark
                || array_key_exists($cell, $clues) || array_key_exists($cell, $shaded)
            ) {
                return $invalid('Invalid shaded cells.');
            }
            $shaded[$cell] = true;
        }

        $openRaw = json_decode((string) $request->query('open', '[]'), true);
        if (! is_array($openRaw) || count($openRaw) > $cellCount) {
            return $invalid('Invalid open cells.');
        }
        $open = [];
        foreach ($openRaw as $cell) {
            if (
                ! is_int($cell) || $cell < 0 || $cell >= $cellCount || $cell === $spark
                || array_key_exists($cell, $clues) || array_key_exists($cell, $shaded) || array_key_exists($cell, $open)
            ) {
                return $invalid('Invalid open cells.');
            }
            $open[$cell] = true;
        }

        $puzzle = new Puzzle($config['rows'], $config['cols'], $spark, $clues, $config['breaks']);

        $state = Engine::initialState($puzzle);
        foreach (array_keys($shaded) as $cell) {
            $state[$cell] = Engine::SHADED;
        }
        foreach (array_keys($open) as $cell) {
            $state[$cell] = Engine::OPEN;
        }

        $result = Engine::nextDeduction($puzzle, $state);
        while ($result['status'] === 'forced' && $result['value'] === Engine::OPEN) {
            $state[$result['cell']] = Engine::OPEN;
            $result = Engine::nextDeduction($puzzle, $state);
        }

        $payload = ['status' => $result['status']];
        if ($result['status'] === 'forced') {
            $payload['cell'] = $result['cell'];

            // Only a forced *firebreak* actually reveals a cell to the
            // player (see the "stays clear" note above) — that's the only
            // outcome worth charging against the daily leaderboard's "clean"
            // (no-hints) badge, so only that case increments the counter.
            // /hint is a generic deduction endpoint that trusts whatever
            // spark/clues the client sends, so this also checks those match
            // today's actual persisted incident before charging anything —
            // otherwise a stale, retried, or hand-crafted request tagged
            // difficulty=daily could inflate an honest player's count for a
            // puzzle that was never their real board. Guests are charged too,
            // in their session (DailyRunStore), and that count follows them
            // onto their account if they sign in.
            if ($difficulty === 'daily' && $this->matchesTodaysDailyIncident($spark, $clues)) {
                DailyRunStore::for($request)->incrementHints(now('UTC')->toDateString());
            } elseif ($difficulty === 'campaign') {
                // The token is already proven valid (parsePuzzleConfig() ->
                // parseCampaignRun() decoded it to get here), so this run
                // unambiguously identifies which board just earned a hint —
                // no separate "matches the real incident" check needed the
                // way daily's does, since the token *is* that check.
                $this->incrementCampaignHints($request->string('token')->toString());
            }
        } elseif ($result['status'] === 'contradiction') {
            $payload['wrong'] = Engine::misplacedShaded($puzzle, $state);
        }

        return response()->json($payload);
    }

    /**
     * The full solution for the incident the client is holding, for the
     * "solve it for me" button: voids the run instead of scoring it. Every
     * server-generated incident is provably solvable by pure deduction (see
     * Engine::generate()), so this is the same rederivation solveDaily()
     * uses for an already-scored daily incident, just reachable for any
     * difficulty/spark/clues combination.
     *
     * For the daily tier specifically, the void is also recorded server-side
     * (DailyRunStore::void()) for whichever account is signed in — never trust
     * the client to not submit a score after asking for the answer, since
     * nothing stops a request straight to this endpoint followed by a POST
     * to /daily/score with the returned cells. That guarantee depends on
     * this endpoint actually having a signed-in account to void: /solve
     * sits outside the `auth` middleware group (see routes/web.php) so
     * guests can use it for practice/custom/endless boards, so
     * difficulty=daily is rejected here for any caller without a session —
     * otherwise a signed-out (or second, cookie-less) request could fetch
     * the day's real solution — every account is handed the same board, so
     * no per-account secret guards it — with no account left to void, then
     * a normal signed-in /daily/score submission would sail through clean.
     */
    public function solve(Request $request): JsonResponse
    {
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();
        // Guests can play the daily now, but the daily has no Solve button
        // (it was removed from the UI), so there is no reason to hand a
        // guest session the day's solution.
        if ($difficulty === 'daily' && $request->user() === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $parsed = $this->parsePuzzleConfig($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        [$config, $spark, $clues] = $parsed;

        $puzzle = new Puzzle($config['rows'], $config['cols'], $spark, $clues, $config['breaks']);
        $state = Engine::deductionSolve($puzzle);

        if ($state === null) {
            return response()->json(['message' => 'Could not solve this incident.'], 422);
        }

        if ($difficulty === 'daily') {
            DailyRunStore::for($request)->void(now('UTC')->toDateString());
        }

        $shaded = [];
        foreach ($state as $cell => $value) {
            if ($value === Engine::SHADED) {
                $shaded[] = $cell;
            }
        }

        return response()->json(['solution' => $shaded]);
    }

    /**
     * Shared spark/clues parsing for hint() and solve(): rows, cols and the
     * break target come from the difficulty tier, not the request, so a
     * client can't puppet the solver into working an arbitrarily large grid.
     * clues arrives as a JSON-encoded query value (a small list of [cell,
     * minute] pairs, not a form field), so it's decoded and shape-checked by
     * hand rather than via the request validator, which only understands
     * PHP-style array query params.
     *
     * @return array{0: array{rows: int, cols: int, breaks: int}, 1: int, 2: array<int, int>}|JsonResponse
     */
    private function parsePuzzleConfig(Request $request): array|JsonResponse
    {
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();

        if ($difficulty === 'campaign') {
            return $this->parseCampaignRun($request);
        }

        $config = $difficulty === 'custom'
            ? $this->resolveCustomConfig($request)
            : PuzzleService::tierConfig($difficulty);

        if ($config === null) {
            return response()->json(['message' => "Unknown difficulty [{$difficulty}]."], 422);
        }

        $cellCount = $config['rows'] * $config['cols'];

        // input(), not query(): this is shared by GET callers (hint()/solve(),
        // spark/clues as query-string values, clues JSON-encoded as a
        // string) and POST callers (submitEndlessScore(), spark/clues as
        // native JSON body values, clues already an array) — input() reads
        // both, but the two shapes still need normalizing, which
        // Engine::parseSparkAndClues() does.
        $parsed = Engine::parseSparkAndClues($cellCount, $request->input('spark'), $request->input('clues'));
        if ($parsed === null) {
            return response()->json(['message' => 'Invalid spark or clues.'], 422);
        }
        [$spark, $clues] = $parsed;

        return [$config, $spark, $clues];
    }

    /**
     * Campaign never trusts a client-supplied spark/clues pair at all —
     * every hint()/solve() call must carry the signed token /campaign/puzzle
     * issued for this run, which is the only source of the board (see
     * CampaignService::decodeRun()). Without this, a client could probe
     * hints against — or ask solve() to reveal the answer for — a
     * fabricated board that no /campaign/puzzle call ever generated.
     *
     * @return array{0: array{rows: int, cols: int, breaks: int}, 1: int, 2: array<int, int>}|JsonResponse
     */
    private function parseCampaignRun(Request $request): array|JsonResponse
    {
        $run = CampaignService::decodeRun($request->input('token'));
        if ($run === null) {
            return response()->json(['message' => 'Invalid or expired token.'], 422);
        }

        $config = CampaignService::levelConfig($run['level']);

        return [$config, $run['spark'], $run['clues']];
    }

    /**
     * Records this run's server-tracked hint count (see
     * CampaignController::submitScore(), which reads it back instead of
     * trusting a client-reported hints_used field) — capped at the same
     * TTL as the token itself so the cache entry never outlives it.
     */
    private function incrementCampaignHints(string $token): void
    {
        $key = CampaignService::hintCacheKey($token);
        Cache::add($key, 0, now()->addSeconds(CampaignService::TOKEN_TTL_SECONDS));
        Cache::increment($key);
    }
}
