<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\AnalyticsEvent;
use Carbon\CarbonImmutable;

/**
 * Persists a validated event batch in ONE multi-row INSERT (WS-19 brief:
 * fire-and-forget, single query per batch).
 *
 * The client timestamp is kept — batched beacons can arrive minutes after the
 * fact and the digest's time-to-first-solve math needs the real gaps — but it
 * is clamped to [received - 48h, received]. That bounds clock skew, stops
 * backdating past retention windows, and stops future-dating rows into
 * immortality.
 */
final class EventRecorder
{
    public const int MAX_BACKDATE_HOURS = 48;

    /**
     * @param  list<array{name: string, ts: string, props?: array<string, mixed>|null}>  $events
     */
    public function record(string $anonId, ?string $userId, array $events, CarbonImmutable $receivedAt): void
    {
        $rows = [];

        foreach ($events as $event) {
            $rows[] = [
                'anon_id' => $anonId,
                'user_id' => $userId,
                'name' => $event['name'],
                // (object) so an empty props record encodes as {} not [].
                'props' => json_encode((object) ($event['props'] ?? []), JSON_THROW_ON_ERROR),
                'created_at' => $this->clamped($event['ts'], $receivedAt),
            ];
        }

        AnalyticsEvent::query()->insert($rows);
    }

    private function clamped(string $ts, CarbonImmutable $receivedAt): CarbonImmutable
    {
        $parsed = CarbonImmutable::parse($ts)->utc();
        $floor = $receivedAt->subHours(self::MAX_BACKDATE_HOURS);

        return $parsed->max($floor)->min($receivedAt);
    }
}
