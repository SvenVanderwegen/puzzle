<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Content delivery (WS-07)
    |--------------------------------------------------------------------------
    |
    | Board JSON normally lives on the CDN (decisions.md #3: content.burnfront.com);
    | GET /daily/{date} returns content_url built from the template below.
    | origin_fallback embeds the board object in the daily response for CDN
    | outages (critique #17) — flipped by ops, never on by default.
    |
    */

    'content' => [
        'cdn_url_template' => env('CONTENT_CDN_URL_TEMPLATE', 'https://content.burnfront.com/puzzles/{id}.json'),
        'origin_fallback' => (bool) env('CONTENT_ORIGIN_FALLBACK', false),

        // Ed25519 public key verifying content manifests (contracts/schemas/
        // calendar.v1.json chain of trust). Raw 32 bytes, base64, or hex.
        'public_key_path' => env('CONTENT_SIGNING_PUBLIC_KEY_PATH', ''),

        // Verified manifests are archived here (local disk) so content:rollback
        // can restore a prior calendar without re-fetching.
        'manifest_archive_dir' => 'content/manifests',
    ],

    /*
    |--------------------------------------------------------------------------
    | Streak-protection alerts (WS-21)
    |--------------------------------------------------------------------------
    |
    | The hourly notifications:streak-risk sweep mails users whose LOCAL clock
    | (users.timezone — its only use, ADR-0002) is inside this evening hour on
    | a day their streak would die unsolved. The deadline itself stays UTC
    | midnight; only the send moment is local. Brief-fixed at 20 (= 20:00).
    |
    */

    'streak_alert' => [
        'local_hour' => 20,
    ],

];
