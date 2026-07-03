<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

// Gate (ADR-0005): contracts/db-schema.sql loaded raw vs `migrate:fresh` on the
// contract migration group; normalized pg_dump diff must be empty.
test('migrations reproduce contracts/db-schema.sql exactly', function (): void {
    $result = Process::timeout(180)->run(['bash', base_path('tests/schema-conformance.sh')]);

    expect($result->exitCode())->toBe(0, 'schema-conformance.sh failed: '.$result->output().$result->errorOutput())
        ->and($result->output())->toContain('SCHEMA OK');
});
