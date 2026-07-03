<?php

declare(strict_types=1);

/*
 * Nightwatch (laravel/nightwatch — allowlisted, ADR-0010 / WS-18).
 *
 * Published from the package and kept env-driven; the file itself is
 * identical for every environment. Burnfront posture on top of the
 * defaults (docs/RUNBOOK.md §6 has the per-environment env values and the
 * alert definitions the owner clicks into the Nightwatch UI):
 *
 *  - capture_request_payload stays false (default): request bodies carry
 *    emails (magic-link) and replays; Nightwatch gets metadata, not payloads.
 *  - redact_headers keeps Cookie/X-XSRF-TOKEN redacted (default) — the
 *    session cookie is the auth credential (ADR-0003).
 *  - sampling defaults to 1.0 everywhere; at this traffic scale full
 *    sampling is affordable and keeps the POST /solves p95 series honest.
 *    NIGHTWATCH_REQUEST_SAMPLE_RATE is the dial if volume ever forces one.
 *  - local/testing run with NIGHTWATCH_ENABLED=false (.env.example,
 *    phpunit.xml); staging and production enable it in Forge with the real
 *    token (owner blocker, tasks/WS-18/STATUS.md). Each site runs its own
 *    `php artisan nightwatch:agent` daemon; staging overrides
 *    NIGHTWATCH_INGEST_URI to its own port (RUNBOOK §6.2).
 */
return [
    'enabled' => env('NIGHTWATCH_ENABLED', true),
    'token' => env('NIGHTWATCH_TOKEN'),
    'deployment' => env('NIGHTWATCH_DEPLOY', env('LARAVEL_CLOUD_DEPLOY_UUID', env('FORGE_DEPLOY_COMMIT', env('VAPOR_COMMIT_HASH')))),
    'server' => env('NIGHTWATCH_SERVER', (string) gethostname()),
    'capture_exception_source_code' => env('NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', true),
    'capture_request_payload' => env('NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD', false),
    'redact_payload_fields' => explode(',', (string) env('NIGHTWATCH_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation')),
    'redact_headers' => explode(',', (string) env('NIGHTWATCH_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN')),

    'sampling' => [
        'requests' => env('NIGHTWATCH_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => env('NIGHTWATCH_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => env('NIGHTWATCH_EXCEPTION_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => env('NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    ],

    'filtering' => [
        'ignore_cache_events' => env('NIGHTWATCH_IGNORE_CACHE_EVENTS', false),
        'ignore_mail' => env('NIGHTWATCH_IGNORE_MAIL', false),
        'ignore_notifications' => env('NIGHTWATCH_IGNORE_NOTIFICATIONS', false),
        'ignore_outgoing_requests' => env('NIGHTWATCH_IGNORE_OUTGOING_REQUESTS', false),
        'ignore_queries' => env('NIGHTWATCH_IGNORE_QUERIES', false),
        'log_level' => env('NIGHTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'debug')),
    ],

    'ingest' => [
        'uri' => env('NIGHTWATCH_INGEST_URI', '127.0.0.1:2407'),
        'timeout' => env('NIGHTWATCH_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => env('NIGHTWATCH_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => env('NIGHTWATCH_INGEST_EVENT_BUFFER', 500),
    ],
];
