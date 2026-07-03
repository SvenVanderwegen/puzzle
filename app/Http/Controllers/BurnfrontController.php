<?php

namespace App\Http\Controllers;

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
}
