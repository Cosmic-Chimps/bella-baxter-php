<?php

declare(strict_types=1);

/**
 * Sample 01 — reads secrets written to .env file by the Bella CLI.
 *
 * Start with:
 *   bella secrets get --app php-01-dotenv-file -o .env && php app.php
 */

require_once __DIR__ . '/vendor/autoload.php';

// Load .env into $_ENV
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Print all 8 secrets in KEY=value format (used by test-samples.sh for validation)
$secrets = [
    'PORT'                       => $_ENV['PORT']                       ?? getenv('PORT')                       ?: '(not set)',
    'DATABASE_URL'               => $_ENV['DATABASE_URL']               ?? getenv('DATABASE_URL')               ?: '(not set)',
    'EXTERNAL_API_KEY'           => $_ENV['EXTERNAL_API_KEY']           ?? getenv('EXTERNAL_API_KEY')           ?: '(not set)',
    'GLEAP_API_KEY'              => $_ENV['GLEAP_API_KEY']              ?? getenv('GLEAP_API_KEY')              ?: '(not set)',
    'ENABLE_FEATURES'            => $_ENV['ENABLE_FEATURES']            ?? getenv('ENABLE_FEATURES')            ?: '(not set)',
    'APP_ID'                     => $_ENV['APP_ID']                     ?? getenv('APP_ID')                     ?: '(not set)',
    'ConnectionStrings__Postgres'=> $_ENV['ConnectionStrings__Postgres'] ?? getenv('ConnectionStrings__Postgres') ?: '(not set)',
    'APP_CONFIG'                 => $_ENV['APP_CONFIG']                 ?? getenv('APP_CONFIG')                 ?: '(not set)',
];

foreach ($secrets as $key => $value) {
    echo "{$key}={$value}" . PHP_EOL;
}
