<?php

declare(strict_types=1);

/**
 * Sample 02 — reads secrets injected directly into the process by bella run.
 *
 * Start with:
 *   bella run --app php-02-process-inject -- php app.php
 *
 * No .env file is written. Secrets are in $_ENV / getenv() from the parent process.
 */

// Print all 8 secrets in KEY=value format (used by test-samples.sh for validation)
$keys = [
    'PORT',
    'DATABASE_URL',
    'EXTERNAL_API_KEY',
    'GLEAP_API_KEY',
    'ENABLE_FEATURES',
    'APP_ID',
    'ConnectionStrings__Postgres',
    'APP_CONFIG',
];

foreach ($keys as $key) {
    $value = getenv($key);
    echo "{$key}=" . ($value !== false ? $value : '(not set)') . PHP_EOL;
}
