<?php

declare(strict_types=1);

use App\Domain\Ops\OpsAlert;
use Illuminate\Support\Facades\Exceptions;

// The scheduled moment: 22:00 UTC on 2026-07-10, T-2h before the 2026-07-11
// daily goes live (routes/console.php).
beforeEach(function (): void {
    $this->travelTo('2026-07-10 22:00:00 UTC');
    Exceptions::fake();
});

test('alerts when tomorrow has no published daily at T-2h', function (): void {
    $this->artisan('ops:content-freshness')
        ->expectsOutputToContain('no published daily for 2026-07-11')
        ->assertExitCode(1);

    Exceptions::assertReported(OpsAlert::class);
});

test('stays silent when tomorrow is imported and published', function (): void {
    seedDaily('2026-07-11');

    $this->artisan('ops:content-freshness')
        ->expectsOutputToContain('2026-07-11 is imported and published')
        ->assertExitCode(0);

    Exceptions::assertNotReported(OpsAlert::class);
});

test("today's board alone does not satisfy the check", function (): void {
    seedDaily('2026-07-10');

    $this->artisan('ops:content-freshness')->assertExitCode(1);

    Exceptions::assertReported(OpsAlert::class);
});
