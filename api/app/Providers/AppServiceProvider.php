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
    }
}
