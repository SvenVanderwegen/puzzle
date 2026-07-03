<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Domain\Ratings\RatingStore;
use App\Domain\Solves\SolveStore;
use App\Domain\Streaks\StreakStore;
use App\Models\AnalyticsEvent;
use App\Models\AuthIdentity;
use App\Models\User;

/**
 * GDPR portability: one JSON document holding every row keyed to the user.
 */
final class UserExporter
{
    public function __construct(
        private readonly SolveStore $solves,
        private readonly RatingStore $ratings,
        private readonly StreakStore $streaks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(User $user): array
    {
        return [
            'format' => 'burnfront.export/1',
            'generated_at' => now()->toJSON(),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'handle' => $user->handle,
                'timezone' => $user->timezone,
                'country' => $user->country,
                'plan' => $user->plan,
                'pro_until' => $user->pro_until?->toJSON(),
                'streak_alert_opt_in' => $user->streak_alert_opt_in,
                'created_at' => $user->created_at->toJSON(),
            ],
            'auth_identities' => AuthIdentity::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (AuthIdentity $identity): array => [
                    'provider' => $identity->provider,
                    'provider_uid' => $identity->provider_uid,
                    'created_at' => $identity->created_at?->toJSON(),
                ])
                ->all(),
            'solves' => $this->solves->exportFor($user->id),
            'rating' => $this->ratings->exportFor($user->id),
            'rating_events' => $this->ratings->exportEventsFor($user->id),
            'streak' => $this->streaks->exportFor($user->id),
            'analytics_events' => AnalyticsEvent::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->get()
                ->map(fn (AnalyticsEvent $event): array => [
                    'name' => $event->name,
                    'props' => $event->props,
                    'created_at' => $event->created_at?->toJSON(),
                ])
                ->all(),
        ];
    }
}
