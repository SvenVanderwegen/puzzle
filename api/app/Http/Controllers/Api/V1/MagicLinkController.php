<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\MagicLinkService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * POST /auth/magic-link, /auth/magic-link/consume, /auth/logout (contracts/openapi.yaml).
 */
final class MagicLinkController extends Controller
{
    /**
     * Constant 202 for every well-formed request, account or not (ADR-0003).
     */
    public function request(Request $request, MagicLinkService $magicLinks): Response
    {
        /** @var array{email: string} $validated */
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);

        $magicLinks->issue($validated['email']);

        return response()->noContent(202);
    }

    /**
     * 204 with a rotated session on success; 410 on invalid/expired/used tokens.
     */
    public function consume(Request $request, MagicLinkService $magicLinks): Response
    {
        /** @var array{token: string} $validated */
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:128'],
        ]);

        $user = $magicLinks->consume($validated['token']);

        if ($user === null) {
            return response()->noContent(410);
        }

        Auth::guard('web')->login($user);

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return response()->noContent();
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }
}
