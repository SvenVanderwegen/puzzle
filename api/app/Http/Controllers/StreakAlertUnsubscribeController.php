<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Signed streak-alert unsubscribe (route email.streak-alert.unsubscribe,
 * WS-21). The URL signature is the whole credential — no login, per email
 * norms — and the route has exactly one power: flipping streak_alert_opt_in
 * OFF for the user named in the signed path. GET serves the human clicking
 * the mailed link; POST is the RFC 8058 one-click target mailbox providers
 * hit headlessly. Idempotent, and the response is identical whether or not
 * the id resolves to a live account (no existence oracle).
 */
final class StreakAlertUnsubscribeController extends Controller
{
    public function __invoke(Request $request, string $userId): Response
    {
        /** @var User|null $user */
        $user = User::query()->whereNull('anonymized_at')->find($userId);

        $user?->forceFill(['streak_alert_opt_in' => false])->save();

        if ($request->isMethod('POST')) {
            return new Response('Streak protection alerts are off.', 200, ['Content-Type' => 'text/plain']);
        }

        return response()->view('streak-alert.unsubscribed');
    }
}
