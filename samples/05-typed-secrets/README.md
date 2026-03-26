# Sample 05: Typed Secrets (`bella secrets generate php`)

**Pattern:** `bella secrets generate php` → typed accessor class → no more `getenv("TYPO")`

---

## How it works

```
bella secrets generate php --project my-project --environment production
↓
secrets.php  (generated, safe to commit — contains NO secret values)
↓
require_once 'secrets.php';
↓
(new AppSecrets())->databaseUrl()  (typed, IDE-autocomplete, runtime validation)
```

## Setup

```bash
composer install

# Authenticate with API key
bella login --api-key bax-xxxxxxxxxxxxxxxxxxxx

export BELLA_BAXTER_URL=http://localhost:5522   # your Bella Baxter instance

# Generate the typed class (re-run whenever secrets change)
bella secrets generate php --app php-05-typed-secrets -o secrets.php

# Run with secrets injected
bella run -- php app.php
```

## What `secrets.php` looks like (generated output)

`bella secrets generate php` reads your project's secret key/type manifest and emits:

```php
final class AppSecrets
{
    private static function require(string $key): string { ... }

    /** HTTP server port */
    public function port(): int { return (int) self::require("PORT"); }

    /** Feature flag */
    public function enableFeatures(): bool { return self::require("ENABLE_FEATURES") === "true"; }

    /** Application UUID */
    public function appId(): string { return self::require("APP_ID") /* UUID */; }

    /** Postgres connection string */
    public function connectionstringsPostgres(): string { return self::require("ConnectionStrings__Postgres"); }

    // ... one method per secret
}
```

**Key naming:** `ConnectionStrings__Postgres` → `connectionstringsPostgres()` (camelCase, `__` treated as word separator).

## Why use typed secrets?

- **Type safety** — `port()` returns `int`, `enableFeatures()` returns `bool`
- **IDE autocomplete** — `$s->` shows all available secrets with doc comments
- **Fail-fast** — missing secret throws `RuntimeException` immediately, not mid-request
- **Safe to commit** — `secrets.php` contains NO values, only key names and types

## Regenerate after adding secrets

```bash
bella secrets generate php --app php-05-typed-secrets -o secrets.php
git add secrets.php   # safe — no values stored
```
