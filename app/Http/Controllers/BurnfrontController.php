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

    public function index(): Response
    {
        return Inertia::render('Burnfront/Index', [
            'difficulties' => PuzzleService::DIFFICULTIES,
            'defaultDifficulty' => PuzzleService::DEFAULT_DIFFICULTY,
        ]);
    }

    public function puzzle(Request $request): JsonResponse
    {
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();

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
     * caching closes that gap). Carries a signed token binding the date and
     * issue time, which submitDailyScore() later uses to measure elapsed
     * time from the server's own clock instead of trusting the client.
     */
    public function daily(): JsonResponse
    {
        $date = now('UTC')->toDateString();

        $payload = Cache::remember(
            $this->dailyCacheKey($date),
            now('UTC')->endOfDay(),
            fn () => $this->puzzles->generateDaily($date)
        );

        $payload['token'] = Crypt::encryptString(json_encode([
            'date' => $date,
            'issuedAt' => now('UTC')->timestamp,
        ]));

        return response()->json($payload);
    }

    /**
     * Records a server-verified completion time for today's daily incident.
     * Never trusts the client's reported elapsed time or board: the token
     * proves when the puzzle was issued, and the submitted cells are
     * replayed against the actual engine before any time is recorded.
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

        if (
            ! is_array($token)
            || ! isset($token['date'], $token['issuedAt'])
            || ! is_string($token['date'])
            || ! is_int($token['issuedAt'])
        ) {
            return response()->json(['message' => 'Invalid token.'], 422);
        }

        $date = now('UTC')->toDateString();
        if ($token['date'] !== $date) {
            return response()->json(['message' => "Not today's incident."], 422);
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

        $timeMs = max(0, now('UTC')->valueOf() - $token['issuedAt'] * 1000);

        if (DailyScore::where('user_id', $request->user()->id)->whereDate('date', $date)->exists()) {
            return response()->json(['message' => "Already on today's board."], 409);
        }

        try {
            $score = DailyScore::create([
                'user_id' => $request->user()->id,
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

    private function dailyCacheKey(string $date): string
    {
        return "burnfront:daily:v1:{$date}";
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
        $config = PuzzleService::tierConfig($difficulty);

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
