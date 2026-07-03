<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Jobs\AnonymizeUser;
use App\Domain\Ratings\RatingStore;
use App\Domain\Streaks\Mail\StreakAlertSubscribedMail;
use App\Domain\Streaks\StreakStore;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * GET/PATCH/DELETE /me (contracts/openapi.yaml #/components/schemas/Me).
 */
final class MeController extends Controller
{
    public function __construct(
        private readonly RatingStore $ratings,
        private readonly StreakStore $streaks,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return new JsonResponse($this->payload($this->user($request)));
    }

    /**
     * The contract allows exactly two mutable fields: timezone and
     * streak_alert_opt_in. Anything else in the body is ignored.
     */
    public function update(Request $request): JsonResponse
    {
        /** @var array{timezone?: string, streak_alert_opt_in?: bool} $validated */
        $validated = $request->validate([
            'timezone' => ['sometimes', 'string', 'timezone:all', 'max:64'],
            'streak_alert_opt_in' => ['sometimes', 'boolean'],
        ]);

        $user = $this->user($request);
        $wasOptedIn = $user->streak_alert_opt_in;
        $user->fill($validated)->save();

        // Opt-in consent trail (WS-21): turning alerts ON queues a confirmation
        // mail carrying a one-click, no-login off switch. The address is
        // already proven owned — magic-link-only auth (ADR-0003) — so the
        // toggle itself completes the opt-in; see WS-21 STATUS decisions.
        if (! $wasOptedIn && $user->streak_alert_opt_in && $user->email !== null) {
            Mail::to($user->email)->queue(new StreakAlertSubscribedMail($user->id));
        }

        return new JsonResponse($this->payload($user));
    }

    /**
     * GDPR erasure: queue the anonymize job, end the session, 202.
     */
    public function destroy(Request $request): Response
    {
        $user = $this->user($request);

        AnonymizeUser::dispatch($user->id);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent(202);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => (string) $user->email,
            'timezone' => $user->timezone,
            'plan' => $user->plan,
            'pro_until' => $user->pro_until?->toJSON(),
            'streak_alert_opt_in' => $user->streak_alert_opt_in,
            'streak' => $this->streaks->summaryFor($user->id),
            'rating' => $this->ratings->summaryFor($user->id),
        ];
    }
}
