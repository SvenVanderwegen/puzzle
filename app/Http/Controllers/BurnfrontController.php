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

        if ($user !== null) {
            $existing = DailyScore::where('user_id', $user->id)
                ->whereDate('date', now('UTC')->toDateString())
                ->first();

            $dailyStatus = [
                'alreadyScored' => $existing !== null,
                'scoreTimeMs' => $existing?->time_ms,
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
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => "Already on today's board."], 409);
        }

        $rank = DailyScore::whereDate('date', $date)->where('time_ms', '<', $score->time_ms)->count() + 1;

        return response()->json(['time_ms' => $score->time_ms, 'rank' => $rank]);
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
     * grid. clues/shaded/open arrive as JSON-encoded query values (they're
     * small lists of [cell, minute] pairs / cell indices, not form fields),
     * so they're decoded and shape-checked by hand rather than via the
     * request validator, which only understands PHP-style array query
     * params. `open` cells (the player's "clear-ground" dots, which the
     * client itself never checks) are folded into the state as committed
     * OPEN alongside `shaded` — without that, a cell the hint already
     * marked "stays clear" is still UNKNOWN next time and gets suggested
     * again forever, instead of the search moving on to the next fact.
     */
    public function hint(Request $request): JsonResponse
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
        $payload = ['status' => $result['status']];
        if ($result['status'] === 'forced') {
            $payload['cell'] = $result['cell'];
            $payload['state'] = $result['value'] === Engine::SHADED ? 'break' : 'open';
        }

        return response()->json($payload);
    }
}
