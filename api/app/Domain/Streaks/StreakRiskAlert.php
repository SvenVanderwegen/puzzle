<?php

declare(strict_types=1);

namespace App\Domain\Streaks;

use App\Domain\Streaks\Mail\StreakRiskMail;
use App\Models\DailyPuzzle;
use App\Models\Streak;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Streak-protection alert sweep (WS-21). Runs hourly; a user is matched in
 * the hour their LOCAL clock (users.timezone — its only use, ADR-0002) reads
 * the configured evening hour. IANA offsets are 15-minute multiples, so the
 * 60-minute local window contains exactly one hourly tick per local day.
 *
 * An alert goes out only when ALL hold:
 *  - streak_alert_opt_in, a live address, not anonymized;
 *  - current streak >= 2 and today's daily unsolved;
 *  - the streak actually dies at the coming UTC midnight — WS-07's safe_until
 *    math decides, so freezes, amnesty days and content outages all suppress
 *    the alert for free;
 *  - nothing was sent to this user for this UTC day yet (atomic Cache::add;
 *    on a marker-write race or crash we prefer a missed alert to a double).
 *
 * Sending is fully isolated from the solve/streak flow: the sweep only ever
 * QUEUES mailables (retry/backoff live on the mailable), and a failing
 * candidate is reported and skipped, never allowed to halt the run.
 */
final class StreakRiskAlert
{
    public function __construct(private readonly StreakService $streaks) {}

    /**
     * Queue every alert due at $now. Returns the number queued.
     */
    public function dispatchDue(CarbonImmutable $now): int
    {
        $now = $now->utc();
        $today = $now->format('Y-m-d');
        $deadline = $now->startOfDay()->addDay();

        /** @var DailyPuzzle|null $daily */
        $daily = DailyPuzzle::query()->find($today);

        // No incident today, or an amnestied one, cannot kill a streak
        // (WS-07 coverage rules) — nothing to warn anyone about.
        if ($daily === null || $daily->amnesty) {
            return 0;
        }

        $queued = 0;

        $candidates = User::query()
            ->where('streak_alert_opt_in', true)
            ->whereNotNull('email')
            ->whereNull('anonymized_at')
            ->whereIn('id', Streak::query()
                ->where('current_len', '>=', 2)
                ->whereNotNull('last_daily_date')
                ->where('last_daily_date', '<', $today)
                ->select('user_id'));

        foreach ($candidates->cursor() as $user) {
            try {
                if ($this->alertIfDue($user, $now, $deadline, $daily)) {
                    $queued++;
                }
            } catch (Throwable $e) {
                report($e); // one bad row must never stop the sweep
            }
        }

        return $queued;
    }

    private function alertIfDue(User $user, CarbonImmutable $now, CarbonImmutable $deadline, DailyPuzzle $daily): bool
    {
        if (! $this->isLocalSendHour($user->timezone, $now)) {
            return false;
        }

        $summary = $this->streaks->summaryFor($user->id);

        if ($summary['current'] < 2) {
            return false;
        }

        // The streak dies at the coming UTC midnight iff safe_until IS that
        // midnight. A freeze in reserve, an amnestied or unpublished day, or
        // an already-dead streak all move safe_until off it.
        if ($summary['safe_until'] !== $deadline->toISOString()) {
            return false;
        }

        // At most once per user per UTC day, across reruns and retries.
        if (! Cache::add($this->onceKey($user->id, $now), true, $now->addHours(36))) {
            return false;
        }

        Mail::to((string) $user->email)->queue(new StreakRiskMail(
            userId: $user->id,
            streakLength: $summary['current'],
            hoursLeft: $this->hoursLeft($now, $deadline),
            incidentNumber: $daily->incident_number,
            date: $daily->date,
            deadline: $deadline,
        ));

        return true;
    }

    private function isLocalSendHour(string $timezone, CarbonImmutable $now): bool
    {
        /** @var int $hour */
        $hour = config('burnfront.streak_alert.local_hour');

        // An unparseable stored zone throws here; the per-user catch above
        // skips the row instead of killing the sweep.
        return $now->setTimezone(new DateTimeZone($timezone))->hour === $hour;
    }

    /**
     * Whole hours until the UTC deadline, rounded UP: for far-west local
     * evenings the true remainder can be under an hour, and "0 hours" reads
     * as already lost. Never overstates by more than 59 minutes.
     */
    private function hoursLeft(CarbonImmutable $now, CarbonImmutable $deadline): int
    {
        return (int) ceil($now->diffInMinutes($deadline) / 60);
    }

    private function onceKey(string $userId, CarbonImmutable $now): string
    {
        return 'streak-alert:'.$userId.':'.$now->format('Y-m-d');
    }
}
