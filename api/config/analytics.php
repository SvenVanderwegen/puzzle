<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | First-party analytics (WS-19, ADR-0008)
    |--------------------------------------------------------------------------
    |
    | The weekly analytics:digest email goes to this address. The example env
    | value is fake; the real owner address is set in Forge. An empty value
    | makes the digest command fail loudly instead of mailing nobody.
    |
    */

    'owner_digest_email' => env('OWNER_DIGEST_EMAIL', ''),

];
