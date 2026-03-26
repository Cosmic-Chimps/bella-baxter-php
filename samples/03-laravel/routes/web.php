<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

// Untyped: all 8 secrets via env()
Route::get('/', function () {
    return response()->json([
        'PORT'                        => env('PORT'),
        'DATABASE_URL'                => env('DATABASE_URL'),
        'EXTERNAL_API_KEY'            => env('EXTERNAL_API_KEY'),
        'GLEAP_API_KEY'               => env('GLEAP_API_KEY'),
        'ENABLE_FEATURES'             => env('ENABLE_FEATURES'),
        'APP_ID'                      => env('APP_ID'),
        'ConnectionStrings__Postgres' => env('ConnectionStrings__Postgres'),
        'APP_CONFIG'                  => env('APP_CONFIG'),
    ]);
});

// Typed: all 8 secrets via BellaAppSecrets
Route::get('/typed', function () {
    $s = new App\Bella\BellaAppSecrets();
    return response()->json([
        'PORT'                        => $s->port(),
        'DATABASE_URL'                => $s->databaseUrl(),
        'EXTERNAL_API_KEY'            => $s->externalApiKey(),
        'GLEAP_API_KEY'               => $s->gleapApiKey(),
        'ENABLE_FEATURES'             => $s->enableFeatures() ? 'true' : 'false',
        'APP_ID'                      => $s->appId(),
        'ConnectionStrings__Postgres' => $s->connectionStringsPostgres(),
        'APP_CONFIG'                  => $s->appConfig(),
    ]);
});
