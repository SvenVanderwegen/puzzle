<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Import\LocalRecordImporter;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /me/import (contracts/openapi.yaml importLocalRecord): the
 * anonymous→account merge. Shape validation only — the anti-fabrication
 * rules live in Domain\Import\LocalRecordImporter.
 */
final class ImportController extends Controller
{
    public function store(Request $request, LocalRecordImporter $importer): JsonResponse
    {
        /** @var array{items: list<array<string, mixed>>} $validated */
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            // Any RFC uuid passes the SCHEMA (format: uuid); the v7-only
            // namespace rule (ADR-0021) is judged per item so one foreign
            // id drops that item, not the batch.
            'items.*.client_solve_id' => ['required', 'string', 'uuid'],
            'items.*.mode' => ['required', 'string', 'in:daily,endless'],
            'items.*.date' => ['sometimes', 'nullable', 'string', 'date_format:Y-m-d'],
            'items.*.shaded' => ['required', 'string', 'regex:/^[01]+$/', 'max:144'],
            'items.*.client_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'items.*.hints' => ['sometimes', 'array:s1,s2,s3'],
            'items.*.hints.s1' => ['required_with:items.*.hints', 'integer', 'min:0', 'max:200'],
            'items.*.hints.s2' => ['required_with:items.*.hints', 'integer', 'min:0', 'max:200'],
            'items.*.hints.s3' => ['required_with:items.*.hints', 'integer', 'min:0', 'max:200'],
            'items.*.solved_at' => ['required', 'string', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();

        return new JsonResponse($importer->import($user, $validated['items']));
    }
}
