<?php

namespace App\Http\Controllers;

use App\Models\DailyScore;
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
    /**
     * How many days back the daily archive (see dailyArchive()) reaches.
     * Bounded rather than "every day since launch" because each archive
     * date has to be generated (Engine::generate(), wall-clock-bounded up
     * to the daily tier's 6s budget) the first time anyone requests it —
     * an unbounded window would let a request pick an arbitrary old date
     * and force a fresh multi-second generation for it.
     */
    private const ARCHIVE_DAYS = 30;

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

        if ($user !== null) {
            $existing = DailyScore::where('user_id', $user->id)
                ->whereDate('date', now('UTC')->toDateString())
                ->first();

            $dailyStatus = [
                'alreadyScored' => $existing !== null,
                'scoreTimeMs' => $existing?->time_ms,
                'streak' => $this->dailyStreak($user->id),
            ];
        }

        return Inertia::render('Burnfront/Start', [
            'dailyStatus' => $dailyStatus,
        ]);
    }

    /**
     * The setup screen between the start menu and an endless game: the
     * player picks a difficulty tier here before a board is ever generated.
     */
    public function endlessSetup(): Response
    {
        return Inertia::render('Burnfront/EndlessSetup', [
            'difficulties' => PuzzleService::DIFFICULTIES,
            'customBounds' => [
                'minDim' => PuzzleService::CUSTOM_MIN_DIM,
                'maxDim' => PuzzleService::CUSTOM_MAX_DIM,
                'minBreaks' => PuzzleService::CUSTOM_MIN_BREAKS,
                'breaksRatio' => PuzzleService::CUSTOM_BREAKS_RATIO,
            ],
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

        if ($difficulty === 'custom') {
            $config = $this->resolveCustomConfig($request);
            if ($config !== null) {
                return Inertia::render('Burnfront/Play', [
                    'mode' => 'endless',
                    'difficulties' => PuzzleService::DIFFICULTIES + ['custom' => $config],
                    'difficulty' => 'custom',
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

        $userId = $request->user()->id;
        $this->bindDailyStart($userId, $date);

        $existing = DailyScore::where('user_id', $userId)->whereDate('date', $date)->first();
        $payload['alreadyScored'] = $existing !== null;
        $payload['scoreTimeMs'] = $existing?->time_ms;
        if ($existing !== null) {
            $payload['solution'] = $this->solveDaily($payload);
            $payload['cleanCase'] = $existing->hints_used === 0;
        }
        $payload['streak'] = $this->dailyStreak($userId);
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

        $cellCount = $puzzlePayload['rows'] * $puzzlePayload['cols'];
        $clues = [];
        foreach ($puzzlePayload['clues'] as $pair) {
            $clues[$pair[0]] = $pair[1];
        }
        $spark = $puzzlePayload['spark'];

        $shaded = [];
        foreach ($shadedRaw as $cell) {
            if (
                ! is_int($cell) || $cell < 0 || $cell >= $cellCount || $cell === $spark
                || array_key_exists($cell, $clues) || array_key_exists($cell, $shaded)
            ) {
                return response()->json(['message' => 'Invalid shaded cells.'], 422);
            }
            $shaded[$cell] = true;
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

        if (DailyScore::where('user_id', $userId)->whereDate('date', $date)->exists()) {
            return response()->json(['message' => "Already on today's board."], 409);
        }

        try {
            $score = DailyScore::create([
                'user_id' => $userId,
                'date' => $date,
                'time_ms' => $timeMs,
                'hints_used' => Cache::get($this->dailyHintKey($userId, $date), 0),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => "Already on today's board."], 409);
        }

        $rank = DailyScore::whereDate('date', $date)->where('time_ms', '<', $score->time_ms)->count() + 1;

        return response()->json(['time_ms' => $score->time_ms, 'rank' => $rank, 'clean' => $score->hints_used === 0]);
    }

    /**
     * @return JsonResponse list of {rank, name, time_ms} for the given (or
     *                      today's) date's fastest verified completions.
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
            'clean' => $score->hints_used === 0,
        ]);

        return response()->json(['date' => $date, 'entries' => $entries]);
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
     * Counts /hint requests server-side for today's real daily incident
     * (see hint()), rather than trusting anything the client could report —
     * the same never-trust-the-client posture as bindDailyStart()/
     * voidDailyScore(). submitDailyScore() reads this into hints_used at
     * the moment a score is recorded; it's never decremented or reset
     * within the day, so re-requesting /daily can't "launder" hints away.
     */
    private function bumpDailyHintCount(int $userId, string $date): void
    {
        $key = $this->dailyHintKey($userId, $date);
        if (! Cache::has($key)) {
            Cache::put($key, 0, now('UTC')->endOfDay());
        }
        Cache::increment($key);
    }

    private function dailyHintKey(int $userId, string $date): string
    {
        return "burnfront:daily:hints:v1:{$date}:{$userId}";
    }

    /**
     * Current and best consecutive-day streaks across every date this
     * account has a recorded daily score for. "Current" only counts if the
     * most recent solved date is today or yesterday — miss two days in a
     * row and it resets to 0 rather than silently carrying a stale streak.
     * One row per (user, date) at most (the table's unique constraint), and
     * bounded by how many days this account has played, so this is cheap
     * enough to compute on every call rather than needing its own column.
     *
     * @return array{current: int, best: int}
     */
    private function dailyStreak(int $userId): array
    {
        $dates = DailyScore::where('user_id', $userId)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($date) => $date->toDateString())
            ->all();

        $best = 0;
        $run = 0;
        $previous = null;
        foreach ($dates as $date) {
            $run = ($previous !== null && Carbon::parse($previous)->addDay()->toDateString() === $date)
                ? $run + 1
                : 1;
            $best = max($best, $run);
            $previous = $date;
        }

        $solved = array_flip($dates);
        $today = now('UTC')->toDateString();
        $cursor = match (true) {
            isset($solved[$today]) => $today,
            isset($solved[now('UTC')->subDay()->toDateString()]) => now('UTC')->subDay()->toDateString(),
            default => null,
        };

        $current = 0;
        while ($cursor !== null && isset($solved[$cursor])) {
            $current++;
            $cursor = Carbon::parse($cursor)->subDay()->toDateString();
        }

        return ['current' => $current, 'best' => $best];
    }

    /**
     * The signed-in player's daily history: every past streak and score,
     * plus the same current/best streak dailyStreak() surfaces elsewhere.
     * Read-only over burnfront_daily_scores — nothing here can be scored or
     * replayed from; see dailyArchive() for actually re-playing a past
     * incident.
     */
    public function dailyHistory(Request $request): Response
    {
        $userId = $request->user()->id;

        $scores = DailyScore::where('user_id', $userId)->orderByDesc('date')->get();

        return Inertia::render('Burnfront/DailyHistory', [
            'totalClosed' => $scores->count(),
            'bestTimeMs' => $scores->min('time_ms'),
            'averageTimeMs' => $scores->isEmpty() ? null : (int) round($scores->avg('time_ms')),
            'cleanCount' => $scores->where('hints_used', 0)->count(),
            'streak' => $this->dailyStreak($userId),
            'entries' => $scores->map(fn (DailyScore $score) => [
                'date' => $score->date->toDateString(),
                'timeMs' => $score->time_ms,
                'clean' => $score->hints_used === 0,
            ]),
        ]);
    }

    /**
     * Lists the past ARCHIVE_DAYS dates a signed-in player can replay for
     * practice (see dailyArchivePlay()/dailyArchivePuzzle()) — today isn't
     * included, since today's incident is only ever played via /daily/play.
     */
    public function dailyArchive(Request $request): Response
    {
        $userId = $request->user()->id;
        $today = now('UTC')->startOfDay();

        $dates = [];
        for ($i = 1; $i <= self::ARCHIVE_DAYS; $i++) {
            $dates[] = $today->copy()->subDays($i)->toDateString();
        }

        $solved = DailyScore::where('user_id', $userId)
            ->whereIn('date', $dates)
            ->get()
            ->keyBy(fn (DailyScore $score) => $score->date->toDateString());

        return Inertia::render('Burnfront/DailyArchive', [
            'entries' => array_map(fn (string $date) => [
                'date' => $date,
                'solved' => $solved->has($date),
                'timeMs' => $solved->get($date)?->time_ms,
            ], $dates),
        ]);
    }

    public function dailyArchivePlay(string $date): Response
    {
        abort_unless($this->isValidArchiveDate($date), 404);

        return Inertia::render('Burnfront/Play', [
            'mode' => 'archive',
            'archiveDate' => $date,
        ]);
    }

    /**
     * The puzzle for a past daily incident, for practice replay only: no
     * token is issued (see submitDailyScore(), which only ever accepts
     * today's date inside that token), so this can never be scored. Reuses
     * PuzzleService::generateDaily(), which is already deterministic per
     * date, and caches the result indefinitely rather than until end-of-day
     * like today's incident — a past date's board never changes.
     */
    public function dailyArchivePuzzle(Request $request, string $date): JsonResponse
    {
        if (! $this->isValidArchiveDate($date)) {
            return response()->json(['message' => 'Invalid archive date.'], 422);
        }

        $payload = Cache::rememberForever(
            $this->dailyCacheKey($date),
            fn () => $this->puzzles->generateDaily($date)
        );

        $userId = $request->user()->id;
        $existing = DailyScore::where('user_id', $userId)->whereDate('date', $date)->first();
        $payload['alreadyScored'] = $existing !== null;
        $payload['scoreTimeMs'] = $existing?->time_ms;
        if ($existing !== null) {
            $payload['solution'] = $this->solveDaily($payload);
        }

        return response()->json($payload);
    }

    /**
     * True for any date strictly between today and ARCHIVE_DAYS ago —
     * shared by dailyArchivePlay() and dailyArchivePuzzle() so both agree on
     * exactly which dates are replayable.
     */
    private function isValidArchiveDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $date, 'UTC');
        } catch (\Exception) {
            return false;
        }
        if ($parsed === false || $parsed->toDateString() !== $date) {
            return false;
        }

        $today = now('UTC')->startOfDay();

        return $parsed->lt($today) && $parsed->gte($today->copy()->subDays(self::ARCHIVE_DAYS));
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
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();

        $parsed = $this->parsePuzzleConfig($request);
        if ($parsed instanceof JsonResponse) {
            return $parsed;
        }
        [$config, $spark, $clues] = $parsed;

        // Only today's real daily incident (not an 'archive' replay, see
        // PuzzleService::tierConfig()) counts toward the "clean case" badge
        // — a practice replay of a past incident was never going to be
        // scored, so hints spent there shouldn't taint anything.
        if ($difficulty === 'daily' && ($user = $request->user()) !== null) {
            $this->bumpDailyHintCount($user->id, now('UTC')->toDateString());
        }
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
        $config = $difficulty === 'custom'
            ? $this->resolveCustomConfig($request)
            : PuzzleService::tierConfig($difficulty);

        if ($config === null) {
            return response()->json(['message' => "Unknown difficulty [{$difficulty}]."], 422);
        }

        $cellCount = $config['rows'] * $config['cols'];
        $invalid = fn (string $message) => response()->json(['message' => $message], 422);

        $sparkRaw = $request->query('spark');
        if (! is_string($sparkRaw) || ! ctype_digit($sparkRaw)) {
            return $invalid('Invalid spark.');
        }
        $spark = (int) $sparkRaw;
        if ($spark >= $cellCount) {
            return $invalid('Invalid spark.');
        }

        $cluesRaw = json_decode((string) $request->query('clues', ''), true);
        if (! is_array($cluesRaw) || count($cluesRaw) > $cellCount) {
            return $invalid('Invalid clues.');
        }
        $clues = [];
        foreach ($cluesRaw as $pair) {
            if (! is_array($pair) || ! array_is_list($pair) || count($pair) !== 2) {
                return $invalid('Invalid clues.');
            }
            [$cell, $minute] = $pair;
            if (
                ! is_int($cell) || $cell < 0 || $cell >= $cellCount || $cell === $spark
                || array_key_exists($cell, $clues) || ! is_int($minute) || $minute < 0
            ) {
                return $invalid('Invalid clues.');
            }
            $clues[$cell] = $minute;
        }

        return [$config, $spark, $clues];
    }
}
