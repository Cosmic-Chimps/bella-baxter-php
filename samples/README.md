# Bella Baxter — PHP SDK Samples

Samples showing how to integrate Bella Baxter secrets into PHP applications.

| Sample | Approach | Best for |
|--------|----------|---------|
| [01-dotenv-file](./01-dotenv-file/) | `bella secrets get --app ... -o .env` + phpdotenv | Simplest setup, any PHP app |
| [02-process-inject](./02-process-inject/) | `bella run --app ... -- php app.php` | Zero SDK code, any command |
| [03-laravel](./03-laravel/) | `BellaBaxterServiceProvider` at boot | Laravel — `env()` works everywhere |
| [04-symfony](./04-symfony/) | `BellaSecretsLoader` event subscriber | Symfony — `$_ENV` before controllers |
| [05-typed-secrets](./05-typed-secrets/) | `bella secrets generate php` → typed class | No magic strings, IDE autocomplete |

---

## Which approach to choose?

```
Do you want zero PHP SDK code?
├── YES → 01 (.env file) or 02 (bella run)
│
└── NO → What framework?
    ├── Laravel  → 03-laravel  (BellaBaxterServiceProvider)
    ├── Symfony  → 04-symfony  (BellaBundle + event subscriber)
    └── Any      → 05-typed-secrets (bella secrets generate php → typed class)
```

---

## Prerequisites

- PHP 8.1+
- Composer
- Bella Baxter account + API key

```bash
# Authenticate
bella login --api-key bax-xxxxxxxxxxxxxxxxxxxx

# Set your Bella Baxter instance URL
export BELLA_BAXTER_URL=http://localhost:5522
```

---

## Bella CLI commands used

| Sample | Command |
|--------|---------|
| 01 | `bella secrets get --app php-01-dotenv-file -o .env` |
| 02 | `bella run --app php-02-process-inject -- php app.php` |
| 03 | `bella exec --app php-03-laravel -- php artisan serve --port=8097` |
| 04 | `bella exec --app php-04-symfony -- php -S localhost:8096 public/index.php` |
| 05 | `bella run --app php-05-typed-secrets -- php app.php` |

---

## Typed secrets (`bella secrets generate php`)

Sample 05 shows a generated `secrets.php` class with typed accessors:

```php
final class AppSecrets
{
    public function port(): int { ... }              // int
    public function databaseUrl(): string { ... }    // string
    public function enableFeatures(): bool { ... }   // bool
    public function appId(): string { ... }          // UUID string
    public function connectionstringsPostgres(): string { ... }  // ConnectionStrings__Postgres
}

// Usage (zero raw getenv() calls):
$s = new AppSecrets();
echo $s->port();                         // 8080  (int)
echo $s->connectionstringsPostgres();    // connection string
```

**Key naming:** `ConnectionStrings__Postgres` → `connectionstringsPostgres()` (camelCase, `__` treated as word separator).

Generate / regenerate:
```bash
bella secrets generate php --app php-05-typed-secrets -o secrets.php
git add secrets.php   # safe — no values
```

---

## Automated tests

```bash
./test-samples.sh bax-xxxxxxxxxxxxxxxxxxxx
```

Tests samples 01, 02, 05 (CLI), and 03-laravel / 04-symfony (HTTP servers) end-to-end.

---

## Secret rotation support

| Sample | Automatic rotation | Notes |
|--------|-------------------|-------|
| `01-dotenv-file` | ❌ No | Re-run `bella secrets get` + reload PHP-FPM |
| `02-process-inject` | ❌ No | Re-run `bella run --` to restart process |
| `03-laravel` | ❌ No | Restart PHP-FPM workers; `BellaBaxterServiceProvider` runs once per worker |
| `04-symfony` | ❌ No | Restart PHP-FPM workers; `BellaSecretsLoader` runs once per worker |
| `05-typed-secrets` | ❌ No | Restart process; typed class reads env at call time |

**PHP-FPM note:** Workers are long-lived (handle many requests after one boot). To pick up rotated secrets: `systemctl reload php-fpm` (zero-downtime graceful restart).
