<?php

declare(strict_types=1);

/**
 * config/bellaBaxter.php — Bella Baxter SDK configuration for Laravel.
 *
 * Values fall back to environment variables so the service provider
 * can be configured via .env without touching this file.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Baxter API URL
    |--------------------------------------------------------------------------
    | The base URL of your Bella Baxter API instance.
    */
    'url' => env('BELLA_BAXTER_URL', 'http://localhost:5000'),

    /*
    |--------------------------------------------------------------------------
    | API Key (Client ID)
    |--------------------------------------------------------------------------
    | The API key ID created in the Bella Baxter dashboard for this environment.
    | Looks like: bella_ak_xxxxx
    */
    'api_key' => env('BELLA_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | End-to-End Encryption
    |--------------------------------------------------------------------------
    | When true, secrets are encrypted client-side (ECDH-AES256GCM) and
    | never visible in plaintext over the wire.
    */
    'e2ee' => env('BELLA_E2EE', true),
];
