<?php

namespace App\Http\Controllers;

use App\Models\CampaignProfile;
use App\Models\DailyIncident;
use App\Models\DailyScore;
use App\Models\EndlessScore;
use App\Support\Burnfront\CampaignService;
use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

class BurnfrontController extends Controller
{
    public function __construct(private readonly PuzzleService $puzzles) {}

    /**
     * The start screen: a menu of game modes plus, for a signed-in player,
     * their standing on today's daily incident. Deliberately reads
     * DailyScore directly instead of going through daily()/bindDailyStart()
     * — the start screen must never bind this account's daily start time
     * just because the player looked at the menu.
     */
    public function start(Request $request): Response
    {
        $user = $request->user();
        $dailyStatus = null;
        $campaignStatus = null;

        if ($user !== null) {
            $existing = DailyScore::where('user_id', $user->id)
                ->whereDate('date', now('UTC')->toDateString())
                ->first();

            $dailyStatus = [
                'alreadyScored' => $existing !== null,
                'scoreTimeMs' => $existing?->time_ms,
                'streak' => $this->computeStreaks($user->id),
            ];

            // Read-only, same as dailyStatus above — viewing the menu must
            // never create a CampaignProfile row; a guest-shaped 0-XP
            // profile is never persisted just because someone looked.
            $totalXp = CampaignProfile::where('user_id', $user->id)->value('total_xp') ?? 0;
            $campaignStatus = CampaignService::progressForXp($totalXp);
        }

        return Inertia::render('Burnfront/Start', [
            'dailyStatus' => $dailyStatus,
            'campaignStatus' => $campaignStatus,
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

        if ($difficulty === 'custom') {
            $config = $this->resolveCustomConfig($request);
            if ($config !== null) {
                return Inertia::render('Burnfront/Play', [
                    'mode' => 'endless',
                    'difficulties' => PuzzleService::DIFFICULTIES + ['custom' => $config],
                    'difficulty' => 'custom',
                    'authenticated' => $authenticated,
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
        ]);
    }

    /**
     * Gated behind the `auth` middleware in routes/web.php — the daily
     * puzzle is only playable while signed in, since a verified time can
     * only ever be posted for an account.
     */
    public function dailyPlay(): Response
    {
        return Inertia::render('Burnfront/Play', [
            'mode' => 'daily',
        ]);
    }

    public function howTo(): Response
    {
        return Inertia::render('Burnfront/HowTo');
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
     * caching closes that gap). Gated behind the `auth` middleware in
     * routes/web.php: the daily puzzle is signed-in-only, so this must never
     * hand the board, clues or token to a guest who could solve it offline
     * and then race the clock after signing in. This also binds
     * (idempotently — first call wins) that account's start time for today,
     * which submitDailyScore() later measures against instead of trusting
     * anything the client reports: refetching /daily can never reset it,
     * so a player can't "restart the clock" right before submitting a
     * board they already knew the answer to.
     */
    public function daily(Request $request): JsonResponse
    {
        $date = now('UTC')->toDateString();

        $payload = Cache::remember(
            $this->dailyCacheKey($date),
            now('UTC')->endOfDay(),
            fn () => $this->puzzles->generateDaily($date)
        );

        $this->persistDailyIncident($date, $payload);

        $userId = $request->user()->id;
        $this->bindDailyStart($userId, $date);

        $existing = DailyScore::where('user_id', $userId)->whereDate('date', $date)->first();
        $payload['alreadyScored'] = $existing !== null;
        $payload['scoreTimeMs'] = $existing?->time_ms;
        if ($existing !== null) {
            $payload['solution'] = $this->solveDaily($payload);
            $payload['hintsUsed'] = $existing->hints_used;
        }
        $payload['token'] = Crypt::encryptString(json_encode(['date' => $date]));

        return response()->json($payload);
    }

    /**
     * Records a server-verified completion time for today's daily incident.
     * Never trusts the client's reported elapsed time or board: elapsed
     * time is measured from this account's bound start (see daily() /
     * bindDailyStart()), not from anything the client sends, and the
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

        $userId = $request->user()->id;

        if (Cache::has($this->dailyVoidKey($userId, $date))) {
            return response()->json(['message' => 'This run was voided after revealing the solution.'], 422);
        }

        $startedAt = Cache::get($this->dailyStartKey($userId, $date));
        if ($startedAt === null) {
            return response()->json(['message' => "Load today's incident while signed in before submitting."], 422);
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
        $hintsUsed = Cache::get($this->dailyHintKey($userId, $date), 0);

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

        $rank = DailyScore::whereDate('date', $date)->where('time_ms', '<', $score->time_ms)->count() + 1;

        return response()->json(['time_ms' => $score->time_ms, 'rank' => $rank, 'hints_used' => $score->hints_used]);
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
        $incidents = DailyIncident::whereDate('date', '>=', $dateStrings->min())
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
            ];
        })->values();

        return Inertia::render('Burnfront/DailyHistory', [
            'entries' => $entries,
            'streak' => $this->computeStreaks($userId),
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
     * running best on a named endless tier — 'custom' grids and untimed
     * tiers are rejected since there's no comparable clock to keep a best
     * against. Unlike the daily incident, endless play has no server-bound
     * start time to measure from, so time_ms is trusted from the client:
     * this is a personal-best record, not a competitive leaderboard, and
     * the board itself is still independently verified before any time is
     * recorded.
     */
    public function submitEndlessScore(Request $request): JsonResponse
    {
        $difficulty = $request->string('difficulty', '')->toString();
        if (! array_key_exists($difficulty, PuzzleService::DIFFICULTIES)) {
            return response()->json(['message' => "Unknown difficulty [{$difficulty}]."], 422);
        }
        if (PuzzleService::DIFFICULTIES[$difficulty]['timed'] === false) {
            return response()->json(['message' => 'This tier has no clock to record.'], 422);
        }

        $parsed = $this->parsePuzzleConfig($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        [$config, $spark, $clues] = $parsed;

        $shaded = $this->shadedCellsFromRequest($config, $spark, $clues, $request->input('shaded'));
        if ($shaded === null) {
            return response()->json(['message' => 'Invalid shaded cells.'], 422);
        }

        $timeMsRaw = $request->input('time_ms');
        if (! is_int($timeMsRaw) || $timeMsRaw < 0) {
            return response()->json(['message' => 'Invalid time.'], 422);
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

        $record = EndlessScore::firstOrNew([
            'user_id' => $request->user()->id,
            'difficulty' => $difficulty,
        ]);
        $record->solved_count = ($record->solved_count ?? 0) + 1;
        $improved = $record->best_time_ms === null || $timeMsRaw < $record->best_time_ms;
        if ($improved) {
            $record->best_time_ms = $timeMsRaw;
        }
        $record->last_solved_at = now();
        $record->save();

        return response()->json([
            'solved_count' => $record->solved_count,
            'best_time_ms' => $record->best_time_ms,
            'improved' => $improved,
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
        $best = $this->endlessBestTimes($request->user()->id);

        $tiers = collect(PuzzleService::DIFFICULTIES)->map(function (array $config, string $key) use ($best) {
            return [
                'difficulty' => $key,
                'label' => $config['label'],
                'timed' => $config['timed'],
                'solvedCount' => $best[$key]['solvedCount'] ?? 0,
                'bestTimeMs' => $best[$key]['bestTimeMs'] ?? null,
            ];
        })->values();

        return Inertia::render('Burnfront/GameHistory', ['tiers' => $tiers]);
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

    private function dailyCacheKey(string $date): string
    {
        return "burnfront:daily:v1:{$date}";
    }

    /**
     * Idempotent: the first call for a given (user, date) wins and every
     * later call is a no-op, so nothing — including refetching /daily
     * right before submitting — can push this account's start time later.
     */
    private function bindDailyStart(int $userId, string $date): void
    {
        Cache::add($this->dailyStartKey($userId, $date), now('UTC')->timestamp, now('UTC')->endOfDay());
    }

    private function dailyStartKey(int $userId, string $date): string
    {
        return "burnfront:daily:start:v1:{$date}:{$userId}";
    }

    /**
     * Marks this account's attempt at today's daily incident as voided —
     * checked by submitDailyScore(), which refuses to record a time once
     * this is set. Never expires before the incident itself does, so asking
     * for the solution can't be "waited out" within the same day.
     */
    private function voidDailyScore(int $userId, string $date): void
    {
        Cache::put($this->dailyVoidKey($userId, $date), true, now('UTC')->endOfDay());
    }

    private function dailyVoidKey(int $userId, string $date): string
    {
        return "burnfront:daily:void:v1:{$date}:{$userId}";
    }

    /**
     * Counts forced-firebreak hints this account has drawn from the
     * incident desk for the given day, for the daily leaderboard's "clean"
     * (no-hints) badge. Cache::add seeds the counter at 0 the first time
     * it's touched so increment() always has something to add to,
     * regardless of cache driver.
     */
    private function incrementDailyHints(int $userId, string $date): void
    {
        $key = $this->dailyHintKey($userId, $date);
        Cache::add($key, 0, now('UTC')->endOfDay());
        Cache::increment($key);
    }

    private function dailyHintKey(int $userId, string $date): string
    {
        return "burnfront:daily:hints:v1:{$date}:{$userId}";
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
     * @return array{current: int, best: int} this account's current daily
     *                                        streak (consecutive days up
     *                                        to and including today or
     *                                        yesterday) and its longest
     *                                        streak ever.
     */
    private function computeStreaks(int $userId): array
    {
        $dates = DailyScore::where('user_id', $userId)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $bySet = array_flip($dates);

        $best = 0;
        $run = 0;
        $prev = null;
        foreach ($dates as $date) {
            $run = ($prev !== null && Carbon::parse($prev)->addDay()->toDateString() === $date) ? $run + 1 : 1;
            $best = max($best, $run);
            $prev = $date;
        }

        $today = now('UTC')->toDateString();
        $cursor = match (true) {
            isset($bySet[$today]) => now('UTC'),
            isset($bySet[now('UTC')->subDay()->toDateString()]) => now('UTC')->subDay(),
            default => null,
        };

        $current = 0;
        while ($cursor !== null && isset($bySet[$cursor->toDateString()])) {
            $current++;
            $cursor = $cursor->copy()->subDay();
        }

        return ['current' => $current, 'best' => $best];
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
     */
    public function hint(Request $request): JsonResponse
    {
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
            $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();
            if ($difficulty === 'daily' && $request->user() !== null) {
                $this->incrementDailyHints($request->user()->id, now('UTC')->toDateString());
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
     * (voidDailyScore()) for whichever account is signed in — never trust
     * the client to not submit a score after asking for the answer, since
     * nothing stops a request straight to this endpoint followed by a POST
     * to /daily/score with the returned cells.
     */
    public function solve(Request $request): JsonResponse
    {
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();

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

        if ($difficulty === 'daily' && $request->user() !== null) {
            $this->voidDailyScore($request->user()->id, now('UTC')->toDateString());
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
        $config = match (true) {
            $difficulty === 'custom' => $this->resolveCustomConfig($request),
            $difficulty === 'campaign' => $this->resolveCampaignConfig($request),
            default => PuzzleService::tierConfig($difficulty),
        };

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
     * Campaign has no client-chosen difficulty at all — the level a hint()/
     * solve() request should be scored against is always this account's
     * *current* level, derived from CampaignProfile::total_xp the same way
     * CampaignController does, never trusted from the request. Returns null
     * (and therefore a 422 from the caller) for a guest, since campaign
     * routes are auth-gated and there's no profile to read without a user.
     *
     * @return array{label: string, rows: int, cols: int, breaks: int, budgetMs: int, minClues: int, timed: bool}|null
     */
    private function resolveCampaignConfig(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }

        $profile = CampaignProfile::firstOrCreate(['user_id' => $user->id], ['total_xp' => 0, 'puzzles_solved' => 0]);

        return CampaignService::levelConfig(CampaignService::levelForXp($profile->total_xp));
    }
}
