<?php

namespace App\Support\Burnfront;

use App\Models\DailyIncident;
use App\Models\DailyScore;
use App\Models\GamePlay;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Where one player's attempt at today's daily fire is tracked: the
 * server-bound start time, the hint count, the "solution revealed" void
 * flag and, for a guest, the verified result.
 *
 * A signed-in player keeps the cache keys BurnfrontController always used
 * (so runs in flight across a deploy keep their clock). A guest keeps the
 * same facts in their session instead: the daily is playable without an
 * account, but the anti-cheat rules stay the same — the clock is bound on
 * the server the first time the board is handed out, refetching never
 * resets it, and a revealed solution voids the run.
 *
 * When a guest signs in or registers, adoptGuestRun() moves that session
 * state onto the account: the earlier of the two start times wins (so
 * looking at the board as a guest and then signing in can never buy a
 * fresh clock), and a guest's verified solve is saved as the account's
 * daily with ranked = false — it counts for the streak, but a clock that
 * never ran on an account stays off the leaderboard.
 */
final class DailyRunStore
{
    private const SESSION_KEY = 'burnfront_daily';

    public function __construct(private readonly Request $request) {}

    public static function for(Request $request): self
    {
        return new self($request);
    }

    public function isGuest(): bool
    {
        return $this->request->user() === null;
    }

    /**
     * Idempotent: the first call for a date wins.
     */
    public function bindStart(string $date): void
    {
        if ($this->isGuest()) {
            $run = $this->guestRun($date);
            if (! isset($run['started_at'])) {
                $run['started_at'] = now('UTC')->timestamp;
                $this->putGuestRun($date, $run);
            }

            return;
        }

        Cache::add(self::startKey($this->userId(), $date), now('UTC')->timestamp, now('UTC')->endOfDay());
    }

    public function startedAt(string $date): ?int
    {
        if ($this->isGuest()) {
            return $this->guestRun($date)['started_at'] ?? null;
        }

        $value = Cache::get(self::startKey($this->userId(), $date));

        return $value === null ? null : (int) $value;
    }

    public function incrementHints(string $date): void
    {
        if ($this->isGuest()) {
            $run = $this->guestRun($date);
            $run['hints'] = ($run['hints'] ?? 0) + 1;
            $this->putGuestRun($date, $run);

            return;
        }

        $key = self::hintKey($this->userId(), $date);
        Cache::add($key, 0, now('UTC')->endOfDay());
        Cache::increment($key);
    }

    public function hints(string $date): int
    {
        if ($this->isGuest()) {
            return (int) ($this->guestRun($date)['hints'] ?? 0);
        }

        return (int) Cache::get(self::hintKey($this->userId(), $date), 0);
    }

    /**
     * Never expires before the fire itself does, so asking for the solution
     * can't be "waited out" within the same day.
     */
    public function void(string $date): void
    {
        if ($this->isGuest()) {
            $run = $this->guestRun($date);
            $run['voided'] = true;
            $this->putGuestRun($date, $run);

            return;
        }

        Cache::put(self::voidKey($this->userId(), $date), true, now('UTC')->endOfDay());
    }

    public function isVoided(string $date): bool
    {
        if ($this->isGuest()) {
            return (bool) ($this->guestRun($date)['voided'] ?? false);
        }

        return Cache::has(self::voidKey($this->userId(), $date));
    }

    /**
     * A guest's verified solve for the date, or null.
     *
     * @return array{time_ms: int, hints_used: int, shaded: list<int>, moves: list<mixed>}|null
     */
    public function guestResult(string $date): ?array
    {
        return $this->guestRun($date)['result'] ?? null;
    }

    /**
     * @param  array{time_ms: int, hints_used: int, shaded: list<int>, moves: list<mixed>}  $result
     */
    public function saveGuestResult(string $date, array $result): void
    {
        $run = $this->guestRun($date);
        $run['result'] = $result;
        $this->putGuestRun($date, $run);
    }

    /**
     * Moves today's guest run (if any) onto $user. Called right after a
     * login or registration, while the session still holds the guest state.
     * A run from an earlier day is simply dropped.
     */
    public static function adoptGuestRun(Request $request, User $user): void
    {
        $stored = $request->session()->pull(self::SESSION_KEY);
        $date = now('UTC')->toDateString();
        if (! is_array($stored) || ($stored['date'] ?? null) !== $date) {
            return;
        }

        $startKey = self::startKey($user->id, $date);
        if (isset($stored['started_at'])) {
            $existing = Cache::get($startKey);
            if ($existing === null || (int) $stored['started_at'] < (int) $existing) {
                Cache::put($startKey, (int) $stored['started_at'], now('UTC')->endOfDay());
            }
        }

        $hintKey = self::hintKey($user->id, $date);
        $guestHints = (int) ($stored['hints'] ?? 0);
        if ($guestHints > (int) Cache::get($hintKey, 0)) {
            Cache::put($hintKey, $guestHints, now('UTC')->endOfDay());
        }

        $voided = (bool) ($stored['voided'] ?? false);
        if ($voided) {
            Cache::put(self::voidKey($user->id, $date), true, now('UTC')->endOfDay());
        }

        $result = $stored['result'] ?? null;
        if ($voided || ! is_array($result)) {
            return;
        }

        // A guest can only have a result after /daily handed them the
        // board, which persists the incident, so this row exists.
        $incident = DailyIncident::whereDate('date', $date)->first();
        if ($incident === null) {
            return;
        }

        if (DailyScore::where('user_id', $user->id)->whereDate('date', $date)->exists()) {
            return;
        }

        try {
            DailyScore::create([
                'user_id' => $user->id,
                'date' => $date,
                'time_ms' => (int) $result['time_ms'],
                'hints_used' => (int) $result['hints_used'],
                'ranked' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        GamePlay::create([
            'user_id' => $user->id,
            'mode' => 'daily',
            'difficulty' => null,
            'date' => $date,
            'rows' => $incident->rows,
            'cols' => $incident->cols,
            'breaks' => $incident->breaks,
            'spark' => $incident->spark,
            'clues' => $incident->clues,
            'shaded_cells' => $result['shaded'],
            'moves' => $result['moves'],
            'time_ms' => (int) $result['time_ms'],
            'hints_used' => (int) $result['hints_used'],
        ]);

        // The guest already saw these points counted up on their device;
        // now that there is an account, they are really theirs.
        PlayerProgress::award($user->id, [
            'rows' => $incident->rows,
            'cols' => $incident->cols,
            'breaks' => $incident->breaks,
            'daily' => true,
            'hintsUsed' => (int) $result['hints_used'],
        ]);
    }

    public static function startKey(int $userId, string $date): string
    {
        return "burnfront:daily:start:v1:{$date}:{$userId}";
    }

    public static function hintKey(int $userId, string $date): string
    {
        return "burnfront:daily:hints:v1:{$date}:{$userId}";
    }

    public static function voidKey(int $userId, string $date): string
    {
        return "burnfront:daily:void:v1:{$date}:{$userId}";
    }

    private function userId(): int
    {
        return $this->request->user()->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function guestRun(string $date): array
    {
        $stored = $this->request->session()->get(self::SESSION_KEY);

        return is_array($stored) && ($stored['date'] ?? null) === $date ? $stored : ['date' => $date];
    }

    /**
     * @param  array<string, mixed>  $run
     */
    private function putGuestRun(string $date, array $run): void
    {
        $run['date'] = $date;
        $this->request->session()->put(self::SESSION_KEY, $run);
    }
}
