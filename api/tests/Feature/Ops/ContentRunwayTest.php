<?php

declare(strict_types=1);

use App\Domain\Ops\OpsAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function (): void {
    $this->travelTo('2026-07-10 08:30:00 UTC');
    Exceptions::fake();
});

/**
 * Seed consecutive published dailies for the given day offsets from today.
 *
 * @param  list<int>  $offsets
 */
function seedRunwayDays(array $offsets): void
{
    $today = CarbonImmutable::parse('2026-07-10', 'UTC');

    foreach ($offsets as $offset) {
        seedDaily($today->addDays($offset)->toDateString());
    }
}

test('exactly 21 consecutive future days stays silent (boundary)', function (): void {
    seedRunwayDays(range(1, 21));

    $this->artisan('ops:content-runway')
        ->expectsOutputToContain('21 consecutive days published (through 2026-07-31)')
        ->assertExitCode(0);

    Exceptions::assertNotReported(OpsAlert::class);
});

test('20 consecutive future days alerts (boundary)', function (): void {
    seedRunwayDays(range(1, 20));

    $this->artisan('ops:content-runway')
        ->expectsOutputToContain('20 consecutive future days published (minimum 21')
        ->assertExitCode(1);

    Exceptions::assertReported(OpsAlert::class);
});

test('a gap ends the runway even when later dates are covered', function (): void {
    // Days 1-3 covered, day 4 missing, days 5-25 covered: the runway is 3.
    seedRunwayDays([...range(1, 3), ...range(5, 25)]);

    $this->artisan('ops:content-runway')
        ->expectsOutputToContain('3 consecutive future days published')
        ->assertExitCode(1);

    Exceptions::assertReported(OpsAlert::class);
});

test("today's board does not count toward the runway", function (): void {
    seedRunwayDays([0]);

    $this->artisan('ops:content-runway')
        ->expectsOutputToContain('0 consecutive future days published')
        ->assertExitCode(1);
});

test('--min override supports staging drills', function (): void {
    seedRunwayDays(range(1, 5));

    $this->artisan('ops:content-runway', ['--min' => '5'])->assertExitCode(0);
    $this->artisan('ops:content-runway', ['--min' => '6'])->assertExitCode(1);
    $this->artisan('ops:content-runway', ['--min' => '0'])->assertExitCode(2);
});
