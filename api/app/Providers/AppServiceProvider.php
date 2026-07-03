<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auth\MagicLinkService;
use App\Domain\Ratings\Events\FailedDailyRecorded;
use App\Domain\Ratings\Events\RatableSolveRecorded;
use App\Domain\Ratings\Listeners\ApplyFailedDaily;
use App\Domain\Ratings\Listeners\ApplyRatableSolve;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Contract-table migrations live in their own group so the
        // schema-conformance gate can run them in isolation (ADR-0005).
        $this->loadMigrationsFrom($this->app->databasePath('migrations/contract'));

        $this->configureRateLimiting();

        // WS-08: the queued rating listeners live under Domain\Ratings —
        // outside the app/Listeners discovery path — so the WS-07 seam is
        // wired explicitly.
        Event::listen(RatableSolveRecorded::class, ApplyRatableSolve::class);
        Event::listen(FailedDailyRecorded::class, ApplyFailedDaily::class);
    }

    /**
     * Throttles per ADR-0003: magic-link requests 3/hour/email (across IPs)
     * plus 5/min/IP; consume 5/min/IP; exports 3/hour/user.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('magic-link-request', function (Request $request): array {
            $email = MagicLinkService::normalizeEmail((string) $request->string('email'));

            return [
                Limit::perHour(3)->by('magic-link-email:'.hash('sha256', $email)),
                Limit::perMinute(5)->by('magic-link-ip:'.(string) $request->ip()),
            ];
        });

        RateLimiter::for('magic-link-consume', function (Request $request): Limit {
            return Limit::perMinute(5)->by('magic-link-consume-ip:'.(string) $request->ip());
        });

        RateLimiter::for('me-export', function (Request $request): Limit {
            $user = $request->user();
            $key = $user instanceof User ? $user->id : (string) $request->ip();

            return Limit::perHour(3)->by('me-export:'.$key);
        });

        // Solve submissions: 30/min/user (contracts/openapi.yaml submitSolve).
        RateLimiter::for('solves', function (Request $request): Limit {
            $user = $request->user();
            $key = $user instanceof User ? $user->id : (string) $request->ip();

            return Limit::perMinute(30)->by('solves:'.$key);
        });

        // Analytics intake: 60 batches/min keyed by anon_id (ADR-0008,
        // openapi recordEvents), PLUS a per-IP ceiling so rotating anon_ids
        // cannot flood the table from one address (verifier finding). IPs
        // live only in transient throttle cache keys; they are never stored.
        RateLimiter::for('events', function (Request $request): array {
            $anonId = $request->input('anon_id');
            $plausible = is_string($anonId) && strlen($anonId) >= 8 && strlen($anonId) <= 64;

            $key = $plausible ? 'anon:'.$anonId : 'ip:'.(string) $request->ip();

            return [
                Limit::perMinute(60)->by('events:'.$key),
                Limit::perMinute(240)->by('events-ip:'.(string) $request->ip()),
            ];
        });

        // Error beacon: 10/min per session (openapi recordFrontendError) —
        // session id when the SPA cookie rode along, IP for cookieless
        // clients — PLUS the same per-IP ceiling against key rotation.
        RateLimiter::for('frontend-errors', function (Request $request): array {
            $key = $request->hasSession()
                ? 'session:'.$request->session()->getId()
                : 'ip:'.(string) $request->ip();

            return [
                Limit::perMinute(10)->by('errors:'.$key),
                Limit::perMinute(40)->by('errors-ip:'.(string) $request->ip()),
            ];
        });
    }
}
