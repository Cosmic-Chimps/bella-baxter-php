<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use BellaBaxter\BaxterClient;
use BellaBaxter\BaxterClientOptions;

// ── Example: fetch secrets with E2EE enabled ──────────────────────────────────

$client = new BaxterClient(new BaxterClientOptions(
    baxterUrl: $_ENV['BAXTER_URL']    ?? 'http://localhost:5000',
    apiKey:    $_ENV['BELLA_API_KEY'] ?? 'bax-example',
));

try {
    $secrets = $client->getAllSecrets();

    echo "Loaded " . count($secrets) . " secret(s):\n";
    foreach ($secrets as $key => $value) {
        // Never log real values in production!
        echo "  {$key} = " . str_repeat('*', min(strlen($value), 8)) . "\n";
    }
} catch (\RuntimeException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
