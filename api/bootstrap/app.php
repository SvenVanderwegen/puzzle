<?php

declare(strict_types=1);

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum stateful SPA mode: same-origin cookie session + CSRF (ADR-0003).
        $middleware->statefulApi();

        // Beacon endpoints (WS-19): navigator.sendBeacon cannot attach the
        // XSRF header, so the stateful-origin CSRF check is waived for these
        // two anonymous fire-and-forget writes. The session cookie is
        // SameSite=Lax, so cross-site POSTs carry no session to abuse.
        //
        // The streak-alert unsubscribe POST (WS-21) is the RFC 8058 one-click
        // target: mailbox providers post to it with no session or token. The
        // route is signed-URL-guarded and can only ever switch alerts off.
        $middleware->validateCsrfTokens(except: [
            'api/v1/events',
            'api/v1/errors',
            'email/streak-alert/unsubscribe/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Error envelope per contracts/openapi.yaml (#/components/schemas/Error).
        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return new JsonResponse([
                'error' => ['code' => 'unauthenticated', 'message' => 'No valid session.'],
            ], 401);
        });

        $exceptions->render(function (ValidationException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return new JsonResponse([
                'error' => ['code' => 'validation_failed', 'message' => $e->getMessage()],
            ], 422);
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return new JsonResponse([
                'error' => ['code' => 'rate_limited', 'message' => 'Too many requests. Retry after the indicated delay.'],
            ], 429, $e->getHeaders());
        });
    })->create();
