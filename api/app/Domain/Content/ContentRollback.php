<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * content:rollback engine (critique #32): repoint the daily calendar back to a
 * previously imported (signature-verified, locally archived) version — FUTURE
 * dates only, the T-48h immutability window is never crossed — in a single
 * transaction. Boards already fetched by players are therefore never swapped
 * out from under them.
 */
final class ContentRollback
{
    /**
     * @return array{content_version: string, restored: int, removed: int}
     */
    public function rollback(string $version): array
    {
        $manifest = $this->archivedManifest($version);
        $target = $this->targetCalendar($manifest, $version);

        $now = CarbonImmutable::now('UTC');

        $counts = DB::transaction(function () use ($target, $version, $now): array {
            $restored = 0;

            /** @var list<DailyPuzzle> $mutableRows */
            $mutableRows = DailyPuzzle::query()
                ->lockForUpdate()
                ->where('date', '>', $now->format('Y-m-d'))
                ->orderBy('date')
                ->get()
                ->filter(fn (DailyPuzzle $row): bool => ContentImporter::isMutableDate($row->date, $now))
                ->values()
                ->all();

            // Delete-then-insert so puzzle_id uniqueness cannot collide while
            // dates swap boards. Existing dates keep their incident_number.
            $keptIncidents = [];
            $deletedDates = [];

            foreach ($mutableRows as $row) {
                if (($target[$row->date] ?? null) === $row->puzzle_id) {
                    continue; // Already what the target version says.
                }

                $keptIncidents[$row->date] = $row->incident_number;
                $deletedDates[$row->date] = true;
                $row->delete();
            }

            $maxIncident = DailyPuzzle::query()->max('incident_number');
            $nextIncident = is_numeric($maxIncident) ? (int) $maxIncident : 0;

            foreach ($target as $date => $puzzleId) {
                if (! ContentImporter::isMutableDate($date, $now) || DailyPuzzle::query()->whereKey($date)->exists()) {
                    continue;
                }

                if (! Puzzle::query()->whereKey($puzzleId)->exists()) {
                    throw new ContentImportException("target version references unknown puzzle {$puzzleId}.");
                }

                DailyPuzzle::query()->create([
                    'date' => $date,
                    'puzzle_id' => $puzzleId,
                    'incident_number' => $keptIncidents[$date] ?? ++$nextIncident,
                    'published_at' => $now,
                    'calendar_version' => $version,
                    'amnesty' => false,
                ]);
                unset($deletedDates[$date]);
                $restored++;
            }

            return ['restored' => $restored, 'removed' => count($deletedDates)];
        });

        return [
            'content_version' => $version,
            'restored' => $counts['restored'],
            'removed' => $counts['removed'],
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private function archivedManifest(string $version): array
    {
        /** @var string $dir */
        $dir = config('burnfront.content.manifest_archive_dir');
        $path = "{$dir}/{$version}.json";

        if (! Storage::disk('local')->exists($path)) {
            throw new ContentImportException("no archived manifest for {$version}; only imported versions can be rolled back to.");
        }

        $bytes = Storage::disk('local')->get($path);

        if ($bytes === null) {
            throw new ContentImportException("cannot read archived manifest for {$version}.");
        }

        $decoded = json_decode($bytes, true);

        if (! is_array($decoded)) {
            throw new ContentImportException("archived manifest for {$version} is corrupt.");
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array<string, string> date -> puzzle id, ascending
     */
    private function targetCalendar(array $manifest, string $version): array
    {
        if (($manifest['schema'] ?? null) !== 'burnfront.calendar/1'
            || ($manifest['content_version'] ?? null) !== $version) {
            throw new ContentImportException("archived manifest for {$version} is not a matching calendar manifest.");
        }

        $days = $manifest['days'] ?? null;

        if (! is_array($days) || ! array_is_list($days)) {
            throw new ContentImportException("archived manifest for {$version} has no day list.");
        }

        $target = [];

        foreach ($days as $day) {
            if (! is_array($day) || ! is_string($day['date'] ?? null) || ! is_string($day['puzzle'] ?? null)) {
                throw new ContentImportException("archived manifest for {$version} has a malformed day entry.");
            }

            $target[$day['date']] = $day['puzzle'];
        }

        ksort($target);

        return $target;
    }
}
