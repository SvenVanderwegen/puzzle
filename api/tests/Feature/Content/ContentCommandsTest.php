<?php

declare(strict_types=1);

use App\Models\ContentImport;
use App\Models\DailyPuzzle;
use App\Models\Puzzle;
use Database\Factories\PuzzleFactory;
use Illuminate\Support\Facades\Storage;

// The WS-05 pipeline is not merged yet, so these tests CONSTRUCT their own
// signed fixtures with sodium: an in-test Ed25519 keypair, puzzle docs
// conforming to contracts/schemas/puzzle.v1.json (boards from the burn-vector
// fixture in PuzzleFactory), and calendar/pack manifests per their schemas,
// signed detached over the exact manifest bytes (calendar.v1.json $comment).

/**
 * @return array{dir: string, secret: string}
 */
function contentWorkspace(): array
{
    $dir = sys_get_temp_dir().'/burnfront-content-'.bin2hex(random_bytes(6));
    mkdir($dir.'/puzzles', 0777, true);

    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);

    file_put_contents($dir.'/signing.pub', sodium_crypto_sign_publickey($keypair));
    config()->set('burnfront.content.public_key_path', $dir.'/signing.pub');

    return ['dir' => $dir, 'secret' => $secret];
}

/**
 * @return array{path: string, sha256: string}
 */
function writePuzzleDoc(string $dir, string $id): array
{
    $doc = json_encode([
        'schema' => 'burnfront.puzzle/1',
        'id' => $id,
        'engine' => ['gen_version' => 'gen-test-9', 'rules_version' => 1],
        'board' => PuzzleFactory::BOARD,
        'grade' => ['tier' => 'lookout', 'score' => 4],
        'certificates' => ['unique' => true, 'deduction_steps' => 4, 'witnessed' => true],
        'solution_sha256' => hash('sha256', PuzzleFactory::VALID_SHADING),
    ], JSON_THROW_ON_ERROR);

    file_put_contents("{$dir}/puzzles/{$id}.json", $doc);

    return ['path' => "puzzles/{$id}.json", 'sha256' => hash('sha256', $doc)];
}

/**
 * @param  array<string, string>  $days  date -> puzzle id
 * @param  array<string, string>  $fileOverrides  path -> sha256 (tamper hook)
 */
function writeCalendarManifest(array $workspace, string $version, array $days, array $fileOverrides = [], bool $sign = true, bool $tamperAfterSigning = false): string
{
    $files = [];

    foreach (array_unique(array_values($days)) as $puzzleId) {
        $file = writePuzzleDoc($workspace['dir'], $puzzleId);
        $files[$file['path']] = $file['sha256'];
    }

    $dates = array_keys($days);
    sort($dates);

    $manifest = json_encode([
        'schema' => 'burnfront.calendar/1',
        'content_version' => $version,
        'from' => $dates[0],
        'to' => $dates[count($dates) - 1],
        'days' => array_map(
            fn (string $date): array => ['date' => $date, 'puzzle' => $days[$date], 'grade_tier' => 'lookout'],
            $dates,
        ),
        'files' => [...$files, ...$fileOverrides],
    ], JSON_THROW_ON_ERROR);

    $path = "{$workspace['dir']}/calendar-{$version}.json";
    file_put_contents($path, $manifest);

    if ($sign) {
        file_put_contents($path.'.sig', sodium_crypto_sign_detached($manifest, $workspace['secret']));
    }

    if ($tamperAfterSigning) {
        file_put_contents($path, str_replace('lookout', 'hotshot', $manifest));
    }

    return $path;
}

beforeEach(function (): void {
    $this->travelTo('2026-07-10 12:00:00 UTC');
    Storage::fake('local');
});

test('content:import imports a signed calendar transactionally', function (): void {
    $ws = contentWorkspace();

    $manifest = writeCalendarManifest($ws, 'v20260714-1', [
        '2026-07-14' => 'bf1-3x3-700001',
        '2026-07-15' => 'bf1-3x3-700002',
    ]);

    $this->artisan('content:import', ['manifest' => $manifest])->assertExitCode(0);

    expect(Puzzle::query()->whereKey('bf1-3x3-700001')->exists())->toBeTrue();

    /** @var Puzzle $puzzle */
    $puzzle = Puzzle::query()->findOrFail('bf1-3x3-700002');
    expect($puzzle->spec)->toEqual(PuzzleFactory::BOARD)
        ->and($puzzle->solution_sha256)->toBe(hash('sha256', PuzzleFactory::VALID_SHADING))
        ->and($puzzle->grade_tier)->toBe('lookout')
        ->and($puzzle->content_version)->toBe('v20260714-1');

    /** @var DailyPuzzle $day14 */
    $day14 = DailyPuzzle::query()->findOrFail('2026-07-14');
    /** @var DailyPuzzle $day15 */
    $day15 = DailyPuzzle::query()->findOrFail('2026-07-15');

    expect($day14->puzzle_id)->toBe('bf1-3x3-700001')
        ->and($day15->incident_number)->toBe($day14->incident_number + 1)
        ->and($day14->calendar_version)->toBe('v20260714-1');

    $this->assertDatabaseHas('content_imports', ['content_version' => 'v20260714-1', 'sig_ok' => true]);

    // The verified manifest is archived for content:rollback.
    Storage::disk('local')->assertExists('content/manifests/v20260714-1.json');
});

test('content:import refuses a bad signature and leaves an audit row', function (): void {
    $ws = contentWorkspace();

    $manifest = writeCalendarManifest($ws, 'v20260714-2', [
        '2026-07-14' => 'bf1-3x3-700003',
    ], tamperAfterSigning: true);

    $this->artisan('content:import', ['manifest' => $manifest])->assertExitCode(1);

    expect(Puzzle::query()->whereKey('bf1-3x3-700003')->exists())->toBeFalse()
        ->and(DailyPuzzle::query()->whereKey('2026-07-14')->exists())->toBeFalse();

    $this->assertDatabaseHas('content_imports', ['content_version' => 'v20260714-2', 'sig_ok' => false]);
});

test('content:import refuses a signature from the wrong key', function (): void {
    $ws = contentWorkspace();

    $manifest = writeCalendarManifest($ws, 'v20260714-3', ['2026-07-14' => 'bf1-3x3-700004']);

    // Swap in a different trusted key after signing.
    $otherKeypair = sodium_crypto_sign_keypair();
    file_put_contents($ws['dir'].'/signing.pub', sodium_crypto_sign_publickey($otherKeypair));

    $this->artisan('content:import', ['manifest' => $manifest])->assertExitCode(1);

    expect(Puzzle::query()->whereKey('bf1-3x3-700004')->exists())->toBeFalse();
});

test('content:import refuses a file hash mismatch and writes nothing', function (): void {
    $ws = contentWorkspace();

    $manifest = writeCalendarManifest($ws, 'v20260714-4', [
        '2026-07-14' => 'bf1-3x3-700005',
    ], fileOverrides: ['puzzles/bf1-3x3-700005.json' => str_repeat('ab', 32)]);

    $this->artisan('content:import', ['manifest' => $manifest])->assertExitCode(1);

    expect(Puzzle::query()->whereKey('bf1-3x3-700005')->exists())->toBeFalse();
    $this->assertDatabaseCount('content_imports', 0);
});

test('content:import refuses to repoint a daily inside the T-48h window', function (): void {
    $ws = contentWorkspace();

    // 2026-07-11 starts in under 48h (now = 2026-07-10 12:00 UTC): immutable.
    $manifest1 = writeCalendarManifest($ws, 'v20260711-1', ['2026-07-11' => 'bf1-3x3-700006']);
    $this->artisan('content:import', ['manifest' => $manifest1])->assertExitCode(0);

    $manifest2 = writeCalendarManifest($ws, 'v20260711-2', ['2026-07-11' => 'bf1-3x3-700007']);
    $this->artisan('content:import', ['manifest' => $manifest2])->assertExitCode(1);

    /** @var DailyPuzzle $day */
    $day = DailyPuzzle::query()->findOrFail('2026-07-11');
    expect($day->puzzle_id)->toBe('bf1-3x3-700006');

    // A mutable date (>= 48h out) may be repointed.
    $manifest3 = writeCalendarManifest($ws, 'v20260714-5', ['2026-07-14' => 'bf1-3x3-700008']);
    $this->artisan('content:import', ['manifest' => $manifest3])->assertExitCode(0);

    $manifest4 = writeCalendarManifest($ws, 'v20260714-6', ['2026-07-14' => 'bf1-3x3-700009']);
    $this->artisan('content:import', ['manifest' => $manifest4])->assertExitCode(0);

    /** @var DailyPuzzle $mutable */
    $mutable = DailyPuzzle::query()->findOrFail('2026-07-14');
    expect($mutable->puzzle_id)->toBe('bf1-3x3-700009');
});

test('content:rollback restores the prior calendar for future dates only', function (): void {
    $ws = contentWorkspace();

    // An immutable daily (tomorrow) that no rollback may touch.
    $tomorrow = seedDaily('2026-07-11');

    $v1 = writeCalendarManifest($ws, 'v20260714-7', [
        '2026-07-14' => 'bf1-3x3-700010',
        '2026-07-15' => 'bf1-3x3-700011',
    ]);
    $this->artisan('content:import', ['manifest' => $v1])->assertExitCode(0);

    $incidents = [
        '2026-07-14' => DailyPuzzle::query()->findOrFail('2026-07-14')->incident_number,
        '2026-07-15' => DailyPuzzle::query()->findOrFail('2026-07-15')->incident_number,
    ];

    // v2 repoints both days and adds a third.
    $v2 = writeCalendarManifest($ws, 'v20260714-8', [
        '2026-07-14' => 'bf1-3x3-700012',
        '2026-07-15' => 'bf1-3x3-700013',
        '2026-07-16' => 'bf1-3x3-700014',
    ]);
    $this->artisan('content:import', ['manifest' => $v2])->assertExitCode(0);

    expect(DailyPuzzle::query()->findOrFail('2026-07-14')->puzzle_id)->toBe('bf1-3x3-700012');

    // Roll back to v1: the two shared days repoint back, the v2-only day goes,
    // incident numbers stay, tomorrow is untouched.
    $this->artisan('content:rollback', ['version' => 'v20260714-7'])->assertExitCode(0);

    expect(DailyPuzzle::query()->findOrFail('2026-07-14')->puzzle_id)->toBe('bf1-3x3-700010')
        ->and(DailyPuzzle::query()->findOrFail('2026-07-15')->puzzle_id)->toBe('bf1-3x3-700011')
        ->and(DailyPuzzle::query()->findOrFail('2026-07-14')->incident_number)->toBe($incidents['2026-07-14'])
        ->and(DailyPuzzle::query()->findOrFail('2026-07-15')->incident_number)->toBe($incidents['2026-07-15'])
        ->and(DailyPuzzle::query()->whereKey('2026-07-16')->exists())->toBeFalse()
        ->and(DailyPuzzle::query()->findOrFail('2026-07-11')->puzzle_id)->toBe($tomorrow->puzzle_id)
        ->and(DailyPuzzle::query()->findOrFail('2026-07-14')->calendar_version)->toBe('v20260714-7');
});

test('content:rollback refuses an unknown version', function (): void {
    contentWorkspace();

    $this->artisan('content:rollback', ['version' => 'v20991231-1'])->assertExitCode(1);
});

test('content:import handles signed pack manifests', function (): void {
    $ws = contentWorkspace();

    $doc = writePuzzleDoc($ws['dir'], 'bf1-3x3-800001');

    $manifest = json_encode([
        'schema' => 'burnfront.pack/1',
        'id' => 'academy-starter',
        'title' => 'Academy: first shift',
        'puzzles' => [
            ['id' => 'bf1-3x3-800001', 'file' => $doc['path'], 'sha256' => $doc['sha256']],
        ],
    ], JSON_THROW_ON_ERROR);

    $path = $ws['dir'].'/pack-academy-starter.json';
    file_put_contents($path, $manifest);
    file_put_contents($path.'.sig', sodium_crypto_sign_detached($manifest, $ws['secret']));

    $this->artisan('content:import', ['manifest' => $path])->assertExitCode(0);

    /** @var Puzzle $puzzle */
    $puzzle = Puzzle::query()->findOrFail('bf1-3x3-800001');
    expect($puzzle->pack_id)->toBe('academy-starter');

    $this->assertDatabaseHas('content_imports', ['content_version' => 'pack:academy-starter', 'sig_ok' => true]);

    ContentImport::query()->count(); // Touch the model so the audit table is exercised end to end.
});
