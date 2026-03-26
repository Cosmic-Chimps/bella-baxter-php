<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bella Baxter URL
    |--------------------------------------------------------------------------
    | Base URL of the Baxter API. Defaults to BELLA_BAXTER_URL env var.
    */
    'url' => env('BELLA_BAXTER_URL', 'https://api.bella-baxter.io'),

    /*
    |--------------------------------------------------------------------------
    | API Key
    |--------------------------------------------------------------------------
    | The API key (client_id:client_secret) for authenticating with Bella Baxter.
    | Generate with: bella apikeys create
    */
    'api_key' => env('BELLA_BAXTER_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Auto-load secrets at boot
    |--------------------------------------------------------------------------
    | When true, BellaServiceProvider automatically fetches all secrets at
    | application boot and injects them into the PHP environment ($_ENV + putenv).
    | Set to false if you prefer to call Bella::getAllSecrets() manually.
    */
    'auto_load' => env('BELLA_BAXTER_AUTO_LOAD', true),
];
