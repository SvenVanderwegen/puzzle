<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Auth\Mail\DeletionConfirmedMail;
use App\Domain\Ratings\RatingStore;
use App\Domain\Solves\SolveStore;
use App\Domain\Streaks\StreakStore;
use App\Models\AuthIdentity;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * GDPR erasure = anonymization (brief; critique #22): the users row survives as an
 * anonymous shell so aggregates stay truthful, everything identifying is removed.
 *
 * - email, handle, country -> NULL; timezone -> 'UTC' (column is NOT NULL; the
 *   default is the only non-identifying value); anonymized_at stamped.
 * - solves rows are kept with user_id set NULL (percentile history survives).
 * - ratings + streaks rows are deleted; rating_events are disowned (user_id NULL).
 * - auth_identities and pending magic-link tokens are deleted (they hold the email).
 */
final class UserAnonymizer
{
    public function __construct(
        private readonly SolveStore $solves,
        private readonly RatingStore $ratings,
        private readonly StreakStore $streaks,
    ) {}

    public function anonymize(string $userId): void
    {
        /** @var string|null $email */
        $email = DB::transaction(function () use ($userId): ?string {
            /** @var User|null $user */
            $user = User::query()->lockForUpdate()->find($userId);

            if ($user === null || $user->anonymized_at !== null) {
                return null;
            }

            $email = $user->email;

            $this->solves->disownAllFor($userId);
            $this->ratings->eraseUser($userId);
            $this->streaks->eraseUser($userId);

            AuthIdentity::query()->where('user_id', $userId)->delete();

            if ($email !== null) {
                MagicLinkToken::query()->where('email', $email)->delete();
            }

            $user->forceFill([
                'email' => null,
                'handle' => null,
                'timezone' => 'UTC',
                'country' => null,
                'streak_alert_opt_in' => false,
                'anonymized_at' => now(),
            ])->save();

            return $email;
        });

        // Deletion receipt (WS-21), queued only after the commit and only on
        // the run that actually anonymized (idempotent replays return null):
        // a mail hiccup can never roll back, delay, or duplicate the erasure.
        if ($email !== null) {
            Mail::to($email)->queue(new DeletionConfirmedMail);
        }
    }
}
