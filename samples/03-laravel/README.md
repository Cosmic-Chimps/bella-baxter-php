# Sample 03: Laravel

**Pattern:** `BellaBaxterServiceProvider` loads all secrets at boot → secrets available via `env()` / `getenv()` everywhere.

This is a **complete, runnable Laravel 11** sample project. Clone it, install dependencies, and run.

---

## Quick Start

```bash
# 1. Install dependencies (includes bella-baxter/sdk from local path)
composer install

# 2. Start the server with secrets injected
bella exec -- php artisan serve --port=8097

# 3. Test the endpoints
curl http://localhost:8097/
curl http://localhost:8097/typed
curl http://localhost:8097/health
```

---

## Endpoints

| Route    | Description                              |
|----------|------------------------------------------|
| `GET /`  | All 8 secrets via `env()` as JSON        |
| `GET /typed` | All 8 secrets via typed `BellaAppSecrets` class |
| `GET /health` | Health check `{"status":"ok"}`       |

---

## How it works

**Boot sequence:**
1. `bella exec` injects `BELLA_BAXTER_API_KEY` + `BELLA_BAXTER_URL` into the process environment
2. Laravel boots → `BellaBaxterServiceProvider::register()` binds `BaxterClient` singleton
3. `BellaBaxterServiceProvider::boot()` calls `BaxterClient::getAllSecrets()`
4. Each secret is written to `$_ENV` and `putenv()` — available via `env()` everywhere
5. Controllers serve secrets from env vars

**Required env vars (provided by `bella exec`):**
```dotenv
BELLA_BAXTER_API_KEY=bax-xxxxxxxxxxxxxxxxxxxx
BELLA_BAXTER_URL=http://localhost:5522
```

---

## Typed secrets

`app/Bella/BellaAppSecrets.php` provides strongly-typed accessors.

In a real project, generate this class automatically:
```bash
bella secrets generate php --app php-03-laravel -o app/Bella/BellaAppSecrets.php
```

---

## Adding to your own Laravel project

1. `composer require bella-baxter/sdk`
2. Copy `app/Providers/BellaBaxterServiceProvider.php` into your project
3. Register in `bootstrap/providers.php`:
   ```php
   return [App\Providers\BellaBaxterServiceProvider::class];
   ```
4. Run with `bella exec --app <your-app> -- php artisan serve`
