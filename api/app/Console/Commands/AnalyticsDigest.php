<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Mail\WeeklyDigestMail;
use App\Domain\Analytics\WeeklyDigest;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Weekly owner digest (ADR-0008; WS-19 brief): activation, median
 * time-to-first-solve, D1/D7, completions by weekday, hint stages per solve,
 * day-3 conversion, share rate, top frontend errors. Recipient comes from
 * config/analytics.php (OWNER_DIGEST_EMAIL, set in Forge).
 */
final class AnalyticsDigest extends Command
{
    protected $signature = 'analytics:digest';

    protected $description = 'Email the weekly first-party analytics digest to the owner';

    public function handle(WeeklyDigest $digest): int
    {
        $to = config('analytics.owner_digest_email');

        if (! is_string($to) || $to === '') {
            $this->error('OWNER_DIGEST_EMAIL is not configured. Digest not sent.');

            return self::FAILURE;
        }

        $report = $digest->compile(CarbonImmutable::now());

        Mail::to($to)->send(new WeeklyDigestMail($report));

        $this->info(sprintf('Digest for %s to %s sent.', $report['window_end'], $to));

        return self::SUCCESS;
    }
}
