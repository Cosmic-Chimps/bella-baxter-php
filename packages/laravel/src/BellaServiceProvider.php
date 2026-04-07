<?php

declare(strict_types=1);

namespace BellaBaxter\Laravel;

use BellaBaxter\BaxterClient;
use BellaBaxter\BaxterClientOptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * BellaServiceProvider — loads secrets from Bella Baxter at application boot.
 *
 * Auto-discovered via composer extra.laravel.providers (no manual registration needed).
 *
 * Laravel 11+ bootstrap/providers.php (explicit):
 *   BellaBaxter\Laravel\BellaServiceProvider::class,
 *
 * Config: config/bella.php (publish with: php artisan vendor:publish --tag=bella-config)
 */
class BellaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/config/bella.php', 'bella');

        $this->app->singleton(BaxterClient::class, function () {
            return new BaxterClient(new BaxterClientOptions(
                baxterUrl:  config('bella.url', env('BELLA_BAXTER_URL', 'http://localhost:5522')),
                apiKey:     config('bella.api_key', env('BELLA_BAXTER_API_KEY', '')),
                privateKey: config('bella.private_key') ?: null,
            ));
        });
    }

    public function boot(): void
    {
        // Publish config file
        $this->publishes([
            __DIR__ . '/config/bella.php' => config_path('bella.php'),
        ], 'bella-config');

        if (!config('bella.auto_load', true)) {
            return;
        }

        try {
            /** @var BaxterClient $client */
            $client  = $this->app->make(BaxterClient::class);
            $secrets = $client->getAllSecrets();

            foreach ($secrets as $key => $value) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }

            $ctx = $client->getKeyContext();
            Log::info(sprintf(
                'Bella: loaded %d secret(s) from project "%s" / environment "%s"',
                count($secrets),
                $ctx['projectSlug'] ?? '',
                $ctx['environmentSlug'] ?? ''
            ));
        } catch (\Throwable $e) {
            if ($this->app->runningInConsole()) {
                fwrite(STDERR, '[Bella] Warning: ' . $e->getMessage() . "\n");
            } else {
                Log::warning('Bella: failed to load secrets', ['error' => $e->getMessage()]);
            }
        }
    }
}
