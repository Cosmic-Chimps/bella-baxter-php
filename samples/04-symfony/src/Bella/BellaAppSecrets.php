<?php

declare(strict_types=1);

namespace App\Bella;

/**
 * Typed accessor class for Bella Baxter secrets.
 *
 * In a real project, generate this with:
 *   bella secrets generate php --app php-04-symfony -o src/Bella/BellaAppSecrets.php
 */
class BellaAppSecrets
{
    private function require(string $key): string
    {
        $v = getenv($key);
        if ($v === false) {
            throw new \RuntimeException("Required secret '{$key}' is not set.");
        }
        return $v;
    }

    /** HTTP server port */
    public function port(): int { return (int) $this->require('PORT'); }

    /** PostgreSQL connection string */
    public function databaseUrl(): string { return $this->require('DATABASE_URL'); }

    /** Third-party API key */
    public function externalApiKey(): string { return $this->require('EXTERNAL_API_KEY'); }

    /** Gleap service key */
    public function gleapApiKey(): string { return $this->require('GLEAP_API_KEY'); }

    /** Feature flag */
    public function enableFeatures(): bool { return $this->require('ENABLE_FEATURES') === 'true'; }

    /** Application UUID */
    public function appId(): string { return $this->require('APP_ID'); }

    /** Postgres connection string (ConnectionStrings__Postgres) */
    public function connectionStringsPostgres(): string { return $this->require('ConnectionStrings__Postgres'); }

    /** JSON app configuration */
    public function appConfig(): string { return $this->require('APP_CONFIG'); }
}
