<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\EventCatalog;
use App\Domain\Analytics\EventRecorder;
use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * POST /api/v1/events — first-party analytics intake (ADR-0008,
 * contracts/openapi.yaml recordEvents). Anonymous, `security: []`, batch of
 * 1–25, 60/min throttled by anon_id (AppServiceProvider `events` limiter; the
 * IP participates only as the throttle fallback key and is NEVER stored).
 *
 * Everything is validated before any DB write — contract shape here, the
 * per-name props record in EventCatalog — then the whole batch lands in one
 * INSERT (EventRecorder). Response is a bodiless 202: fire-and-forget.
 */
final class EventController extends Controller
{
    /** RFC 3339 date-time, the contract's `ts` format. */
    private const string TS_PATTERN = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[Zz]|[+-]\d{2}:\d{2})$/';

    public function store(Request $request, EventRecorder $recorder): Response
    {
        /** @var array{anon_id: string, events: list<array{name: string, ts: string, props?: array<string, mixed>|null}>} $data */
        $data = $request->validate([
            'anon_id' => ['required', 'string', 'min:8', 'max:64'],
            'events' => ['required', 'array', 'min:1', 'max:25'],
            'events.*' => ['array'],
            'events.*.name' => ['required', 'string', Rule::in(EventCatalog::names())],
            'events.*.ts' => ['required', 'string', 'regex:'.self::TS_PATTERN],
            'events.*.props' => ['sometimes', 'nullable', 'array', 'max:'.EventCatalog::MAX_PROPS],
        ]);

        $this->rejectUnknownKeys($request);

        foreach ($data['events'] as $index => $event) {
            $error = EventCatalog::validateProps($event['name'], $event['props'] ?? []);

            if ($error !== null) {
                throw ValidationException::withMessages(["events.{$index}.props" => $error]);
            }
        }

        // Attached when the SPA session cookie authenticates the request; the
        // column has no FK, so the id survives account anonymization as an
        // orphaned opaque string (docs/gdpr.md).
        $user = $request->user();
        $userId = $user instanceof User ? $user->id : null;

        $recorder->record($data['anon_id'], $userId, $data['events'], CarbonImmutable::now());

        return response()->noContent(202);
    }

    /**
     * The contract says `additionalProperties: false` on the body and on each
     * event. Laravel's validator merely ignores extra keys (validate()
     * returns a stripped copy), so this inspects the RAW json body — still
     * ahead of any DB write.
     */
    private function rejectUnknownKeys(Request $request): void
    {
        /** @var array<string, mixed> $body */
        $body = $request->json()->all();

        $unknown = array_diff(array_keys($body), ['anon_id', 'events']);

        if ($unknown !== []) {
            throw ValidationException::withMessages(['body' => sprintf('Unknown field "%s".', implode('", "', $unknown))]);
        }

        $events = is_array($body['events'] ?? null) ? $body['events'] : [];

        foreach ($events as $index => $event) {
            if (! is_array($event)) {
                continue; // already rejected by the shape rules
            }

            $extra = array_diff(array_keys($event), ['name', 'ts', 'props']);

            if ($extra !== []) {
                throw ValidationException::withMessages(['events.'.(string) $index => sprintf('Unknown field "%s".', implode('", "', $extra))]);
            }
        }
    }
}
