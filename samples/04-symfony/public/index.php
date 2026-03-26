<?php

use App\Kernel;

require_once dirname(__DIR__).'/vendor/autoload.php';

// Load .env for local config (APP_ENV, APP_SECRET, etc.)
// Process-level env vars injected by bella exec take precedence and are NOT overridden.
$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        if (getenv($key) === false && !isset($_ENV[$key])) {
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
            putenv("$key=$val");
        }
    }
}

$env   = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'prod';
$debug = (bool) ($_ENV['APP_DEBUG'] ?? ($_SERVER['APP_DEBUG'] ?? false));

$kernel   = new Kernel($env, $debug);
$request  = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
