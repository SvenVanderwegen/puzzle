<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Solves\Board;
use App\Domain\Solves\InvalidBoardSpec;
use App\Models\ContentImport;
use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * content:import engine (WS-07). Chain of trust per contracts/schemas/
 * calendar.v1.json: a detached Ed25519 signature (<manifest>.sig, over the
 * exact manifest bytes) -> the manifest's sha256 file list -> the files.
 * Refused signatures still leave a content_imports row (sig_ok = false) so
 * tampering attempts are auditable; nothing else is written on refusal.
 *
 * Verified calendar manifests are archived to local storage so
 * content:rollback can restore a prior calendar without re-fetching.
 */
final class ContentImporter
{
    /**
     * @return array{kind: string, content_version: string, puzzles: int, days: int}
     */
    public function import(string $manifestLocation): array
    {
        $manifestBytes = $this->fetchBytes($manifestLocation, 'manifest');
        $signatureBytes = $this->fetchBytes($manifestLocation.'.sig', 'signature');

        $manifest = $this->decodeJsonObject($manifestBytes, 'manifest');

        if (! $this->signatureValid($signatureBytes, $manifestBytes)) {
            ContentImport::query()->create([
                'content_version' => $this->claimedVersion($manifest),
                'manifest_sha256' => hash('sha256', $manifestBytes),
                'sig_ok' => false,
            ]);

            throw new ContentImportException('Ed25519 signature verification failed; import refused.');
        }

        $schema = $manifest['schema'] ?? null;

        return match ($schema) {
            'burnfront.calendar/1' => $this->importCalendar($manifestLocation, $manifestBytes, $manifest),
            'burnfront.pack/1' => $this->importPack($manifestLocation, $manifestBytes, $manifest),
            default => throw new ContentImportException('Unknown manifest schema.'),
        };
    }

    /**
     * T-48h immutability (critique #32): a daily is frozen once its UTC start
     * is less than 48 hours away.
     */
    public static function isMutableDate(string $date, CarbonImmutable $now): bool
    {
        return CarbonImmutable::parse($date, 'UTC')->startOfDay()
            ->greaterThanOrEqualTo($now->addHours(48));
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array{kind: string, content_version: string, puzzles: int, days: int}
     */
    private function importCalendar(string $location, string $manifestBytes, array $manifest): array
    {
        $version = $this->claimedVersion($manifest);

        if (preg_match('/^v[0-9]{8}-[0-9]+$/', $version) !== 1) {
            throw new ContentImportException('calendar content_version must match ^v[0-9]{8}-[0-9]+$.');
        }

        $files = $manifest['files'] ?? null;
        $days = $manifest['days'] ?? null;

        if (! is_array($files) || $files === [] || ! is_array($days) || $days === [] || ! array_is_list($days)) {
            throw new ContentImportException('calendar manifest needs non-empty days and files.');
        }

        $docs = $this->verifiedPuzzleDocs($location, $files);

        $calendar = [];

        foreach ($days as $day) {
            if (! is_array($day) || ! is_string($day['date'] ?? null) || ! is_string($day['puzzle'] ?? null)
                || ! in_array($day['grade_tier'] ?? null, ['lookout', 'crew', 'hotshot'], true)
                || ! $this->isDate($day['date'])) {
                throw new ContentImportException('malformed calendar day entry.');
            }

            $doc = $docs[$day['puzzle']] ?? null;

            if ($doc !== null && $doc['grade_tier'] !== $day['grade_tier']) {
                throw new ContentImportException("grade_tier mismatch for {$day['puzzle']} on {$day['date']}.");
            }

            if ($doc === null && ! Puzzle::query()->whereKey($day['puzzle'])->exists()) {
                throw new ContentImportException("calendar references unknown puzzle {$day['puzzle']}.");
            }

            $calendar[$day['date']] = $day['puzzle'];
        }

        ksort($calendar);

        DB::transaction(function () use ($docs, $calendar, $version, $manifestBytes): void {
            $this->upsertPuzzles($docs, $version, null);
            $this->applyCalendar($calendar, $version);

            ContentImport::query()->create([
                'content_version' => $version,
                'manifest_sha256' => hash('sha256', $manifestBytes),
                'sig_ok' => true,
            ]);
        });

        $this->archiveManifest($version, $manifestBytes);

        return ['kind' => 'calendar', 'content_version' => $version, 'puzzles' => count($docs), 'days' => count($calendar)];
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array{kind: string, content_version: string, puzzles: int, days: int}
     */
    private function importPack(string $location, string $manifestBytes, array $manifest): array
    {
        $packId = $manifest['id'] ?? null;
        $entries = $manifest['puzzles'] ?? null;

        if (! is_string($packId) || preg_match('/^[a-z0-9-]+$/', $packId) !== 1
            || ! is_array($entries) || $entries === [] || ! array_is_list($entries)) {
            throw new ContentImportException('malformed pack manifest.');
        }

        $files = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null)
                || ! is_string($entry['file'] ?? null) || ! is_string($entry['sha256'] ?? null)) {
                throw new ContentImportException('malformed pack puzzle entry.');
            }

            $files[$entry['file']] = $entry['sha256'];
        }

        $docs = $this->verifiedPuzzleDocs($location, $files);

        foreach ($entries as $entry) {
            /** @var array{id: string, file: string} $entry */
            $doc = null;

            foreach ($docs as $candidate) {
                if ($candidate['id'] === $entry['id']) {
                    $doc = $candidate;
                }
            }

            if ($doc === null) {
                throw new ContentImportException("pack entry {$entry['id']} does not match its puzzle doc.");
            }
        }

        // Packs carry no content_version of their own; the audit trail and the
        // puzzles rows record the pack pseudo-version.
        $version = 'pack:'.$packId;

        DB::transaction(function () use ($docs, $version, $packId, $manifestBytes): void {
            $this->upsertPuzzles($docs, $version, $packId);

            ContentImport::query()->create([
                'content_version' => $version,
                'manifest_sha256' => hash('sha256', $manifestBytes),
                'sig_ok' => true,
            ]);
        });

        return ['kind' => 'pack', 'content_version' => $version, 'puzzles' => count($docs), 'days' => 0];
    }

    /**
     * Fetch every referenced file, enforce its manifest sha256, and validate it
     * as a burnfront.puzzle/1 doc.
     *
     * @param  array<array-key, mixed>  $files  relative path -> sha256
     * @return array<string, array{id: string, board: Board, rows: int, cols: int, breaks: int, grade_tier: string, grade_score: float|int, solution_sha256: string, gen_version: string}>
     */
    private function verifiedPuzzleDocs(string $manifestLocation, array $files): array
    {
        $docs = [];

        foreach ($files as $path => $sha256) {
            if (! is_string($path) || ! is_string($sha256)) {
                throw new ContentImportException('malformed files map.');
            }

            $bytes = $this->fetchBytes($this->siblingLocation($manifestLocation, $path), $path);

            if (! hash_equals($sha256, hash('sha256', $bytes))) {
                throw new ContentImportException("file hash mismatch for {$path}; import refused.");
            }

            $doc = $this->validatePuzzleDoc($this->decodeJsonObject($bytes, $path), $path);
            $docs[$doc['id']] = $doc;
        }

        return $docs;
    }

    /**
     * Minimal burnfront.puzzle/1 conformance (contracts/schemas/puzzle.v1.json):
     * enough to refuse structurally broken content without a JSON-Schema engine
     * (no such dependency is allowlisted).
     *
     * @param  array<array-key, mixed>  $doc
     * @return array{id: string, board: Board, rows: int, cols: int, breaks: int, grade_tier: string, grade_score: float|int, solution_sha256: string, gen_version: string}
     */
    private function validatePuzzleDoc(array $doc, string $path): array
    {
        $id = $doc['id'] ?? null;
        $engine = $doc['engine'] ?? null;
        $grade = $doc['grade'] ?? null;
        $certificates = $doc['certificates'] ?? null;
        $solutionSha = $doc['solution_sha256'] ?? null;

        if (($doc['schema'] ?? null) !== 'burnfront.puzzle/1'
            || ! is_string($id) || preg_match('/^bf1-[0-9]+x[0-9]+-[0-9]{6}$/', $id) !== 1
            || ! is_array($engine) || ! is_string($engine['gen_version'] ?? null) || ($engine['rules_version'] ?? null) !== 1
            || ! is_array($grade) || ! in_array($grade['tier'] ?? null, ['lookout', 'crew', 'hotshot'], true)
            || ! (is_int($grade['score'] ?? null) || is_float($grade['score'] ?? null)) || $grade['score'] < 0
            || ! is_array($certificates) || ($certificates['unique'] ?? null) !== true
            || ($certificates['witnessed'] ?? null) !== true
            || ! is_int($certificates['deduction_steps'] ?? null) || $certificates['deduction_steps'] < 1
            || ! is_string($solutionSha) || preg_match('/^[0-9a-f]{64}$/', $solutionSha) !== 1
            || ! is_array($doc['board'] ?? null)) {
            throw new ContentImportException("{$path} is not a valid burnfront.puzzle/1 doc.");
        }

        try {
            /** @var array<array-key, mixed> $boardSpec */
            $boardSpec = $doc['board'];
            $board = Board::fromArray($boardSpec);
        } catch (InvalidBoardSpec $e) {
            throw new ContentImportException("{$path} board rejected: {$e->getMessage()}");
        }

        /** @var array{tier: string, score: float|int} $grade */
        /** @var array{gen_version: string} $engine */
        return [
            'id' => $id,
            'board' => $board,
            'rows' => $board->rows,
            'cols' => $board->cols,
            'breaks' => $board->breaks,
            'grade_tier' => $grade['tier'],
            'grade_score' => $grade['score'],
            'solution_sha256' => $solutionSha,
            'gen_version' => $engine['gen_version'],
        ];
    }

    /**
     * @param  array<string, array{id: string, board: Board, rows: int, cols: int, breaks: int, grade_tier: string, grade_score: float|int, solution_sha256: string, gen_version: string}>  $docs
     */
    private function upsertPuzzles(array $docs, string $contentVersion, ?string $packId): void
    {
        foreach ($docs as $doc) {
            Puzzle::query()->updateOrCreate(
                ['id' => $doc['id']],
                [
                    'spec' => $doc['board']->toArray(),
                    'rows' => $doc['rows'],
                    'cols' => $doc['cols'],
                    'n_breaks' => $doc['breaks'],
                    'grade_tier' => $doc['grade_tier'],
                    'grade_score' => $doc['grade_score'],
                    'solution_sha256' => $doc['solution_sha256'],
                    'gen_version' => $doc['gen_version'],
                    'content_version' => $contentVersion,
                    'pack_id' => $packId,
                    'imported_at' => CarbonImmutable::now('UTC'),
                ],
            );
        }
    }

    /**
     * Upsert the daily calendar. Existing dates keep their incident_number;
     * repointing a date to a different board is only allowed while the date is
     * still mutable (T-48h); new dates are numbered sequentially in date order.
     *
     * @param  array<string, string>  $calendar  date -> puzzle id, ascending
     */
    private function applyCalendar(array $calendar, string $version): void
    {
        $now = CarbonImmutable::now('UTC');
        $maxIncident = DailyPuzzle::query()->max('incident_number');
        $nextIncident = is_numeric($maxIncident) ? (int) $maxIncident : 0;

        // Repointed dates are deleted first so puzzle_id uniqueness cannot
        // collide mid-upsert (e.g. two future dates swapping boards).
        $keptIncidents = [];

        foreach ($calendar as $date => $puzzleId) {
            /** @var DailyPuzzle|null $existing */
            $existing = DailyPuzzle::query()->lockForUpdate()->find($date);

            if ($existing === null || $existing->puzzle_id === $puzzleId) {
                continue;
            }

            if (! self::isMutableDate($date, $now)) {
                throw new ContentImportException("daily {$date} is inside the T-48h immutability window; import refused.");
            }

            $keptIncidents[$date] = $existing->incident_number;
            $existing->delete();
        }

        foreach ($calendar as $date => $puzzleId) {
            /** @var DailyPuzzle|null $existing */
            $existing = DailyPuzzle::query()->lockForUpdate()->find($date);

            if ($existing !== null) {
                $existing->calendar_version = $version;
                $existing->save();

                continue;
            }

            DailyPuzzle::query()->create([
                'date' => $date,
                'puzzle_id' => $puzzleId,
                'incident_number' => $keptIncidents[$date] ?? ++$nextIncident,
                'published_at' => $now,
                'calendar_version' => $version,
                'amnesty' => false,
            ]);
        }
    }

    private function archiveManifest(string $version, string $bytes): void
    {
        /** @var string $dir */
        $dir = config('burnfront.content.manifest_archive_dir');

        Storage::disk('local')->put("{$dir}/{$version}.json", $bytes);
    }

    private function signatureValid(string $signature, string $manifestBytes): bool
    {
        /** @var string $keyPath */
        $keyPath = config('burnfront.content.public_key_path');

        if ($keyPath === '') {
            throw new ContentImportException('CONTENT_SIGNING_PUBLIC_KEY_PATH is not configured.');
        }

        $publicKey = $this->decodeKeyMaterial(
            $this->fetchBytes($keyPath, 'public key'),
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'public key',
        );

        $rawSignature = $this->decodeKeyMaterial($signature, SODIUM_CRYPTO_SIGN_BYTES, 'signature');

        return sodium_crypto_sign_verify_detached($rawSignature, $manifestBytes, $publicKey);
    }

    /**
     * Key material may be raw bytes, hex, or base64 (the WS-05 pipeline is not
     * merged yet; all three encodings are accepted so the dev/prod key format
     * cannot brick the import path).
     *
     * @return non-empty-string
     */
    private function decodeKeyMaterial(string $bytes, int $expectedLength, string $label): string
    {
        $material = null;

        if (strlen($bytes) === $expectedLength) {
            $material = $bytes;
        } else {
            $text = trim($bytes);

            if (strlen($text) === 2 * $expectedLength && preg_match('/^[0-9a-fA-F]+$/', $text) === 1) {
                $material = (string) hex2bin($text);
            } else {
                $decoded = base64_decode($text, true);

                if ($decoded !== false && strlen($decoded) === $expectedLength) {
                    $material = $decoded;
                }
            }
        }

        if ($material === null || $material === '') {
            throw new ContentImportException("{$label} is not raw/hex/base64 Ed25519 material of {$expectedLength} bytes.");
        }

        return $material;
    }

    private function fetchBytes(string $location, string $label): string
    {
        $bytes = @file_get_contents($location);

        if ($bytes === false) {
            throw new ContentImportException("cannot read {$label} at {$location}.");
        }

        return $bytes;
    }

    private function siblingLocation(string $manifestLocation, string $relativePath): string
    {
        if (str_contains($relativePath, '..')) {
            throw new ContentImportException('file paths may not traverse upward.');
        }

        $slash = strrpos($manifestLocation, '/');

        return $slash === false ? $relativePath : substr($manifestLocation, 0, $slash + 1).$relativePath;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decodeJsonObject(string $bytes, string $label): array
    {
        $decoded = json_decode($bytes, true);

        if (! is_array($decoded)) {
            throw new ContentImportException("{$label} is not a JSON object.");
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     */
    private function claimedVersion(array $manifest): string
    {
        $version = $manifest['content_version'] ?? null;

        if (is_string($version) && $version !== '') {
            return substr($version, 0, 64);
        }

        $id = $manifest['id'] ?? null;

        return is_string($id) && $id !== '' ? 'pack:'.substr($id, 0, 58) : 'unknown';
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        [$y, $m, $d] = array_map(intval(...), explode('-', $value));

        return checkdate($m, $d, $y);
    }
}
