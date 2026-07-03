<?php

namespace App\Http\Controllers;

use App\Support\Burnfront\Engine;
use App\Support\Burnfront\Puzzle;
use App\Support\Burnfront\PuzzleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BurnfrontController extends Controller
{
    public function __construct(private readonly PuzzleService $puzzles) {}

    public function index(): View
    {
        return view('burnfront.index', [
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
     * One step of pure deduction for the board the client is holding: the
     * incident (spark + clues, as handed out by puzzle()) plus whichever
     * cells the player has already committed as firebreaks. Rows, cols and
     * the break target are taken from the difficulty tier, not the request,
     * so a client can't puppet the solver into working an arbitrarily large
     * grid. clues/shaded arrive as JSON-encoded query values (they're small
     * lists of [cell, minute] pairs / cell indices, not form fields), so
     * they're decoded and shape-checked by hand rather than via the request
     * validator, which only understands PHP-style array query params.
     */
    public function hint(Request $request): JsonResponse
    {
        $difficulty = $request->string('difficulty', PuzzleService::DEFAULT_DIFFICULTY)->toString();
        $config = PuzzleService::DIFFICULTIES[$difficulty] ?? null;

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

        $puzzle = new Puzzle($config['rows'], $config['cols'], $spark, $clues, $config['breaks']);

        $state = Engine::initialState($puzzle);
        foreach (array_keys($shaded) as $cell) {
            $state[$cell] = Engine::SHADED;
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
