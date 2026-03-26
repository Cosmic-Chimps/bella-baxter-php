# Sample 01: `.env` File Approach (PHP)

**Pattern:** CLI writes secrets to a `.env` file → app reads it with `vlucas/phpdotenv`.

This works with **any PHP framework** — Laravel, Symfony, Slim, vanilla PHP, etc.

---

## How it works

```
bella secrets get -o .env   →   .env file on disk   →   Dotenv::load()   →   $_ENV / getenv()
```

## Setup

```bash
composer install

# Authenticate with API key
bella login --api-key bax-xxxxxxxxxxxxxxxxxxxx

export BELLA_BAXTER_URL=http://localhost:5522   # your Bella Baxter instance

# Pull secrets to .env file, then run
bella secrets get -o .env && php app.php
```

## Works with any framework

```bash
# Laravel (artisan auto-loads .env)
bella secrets get -o .env && php artisan serve

# Symfony
bella secrets get -o .env.local && php bin/console server:run

# Slim / plain PHP
bella secrets get -o .env && php -S localhost:3000 index.php

# CLI scripts
bella secrets get -o .env && php bin/import.php
```

## Security notes

- Add `.env` to `.gitignore` — never commit secrets
- Laravel already loads `.env` automatically — you just need Bella to write it first
- Symfony uses `.env.local` (not committed) — use `-o .env.local`

## Secret rotation

❌ **Not supported automatically.** The `.env` file is written once by `bella secrets get` and read once per PHP-FPM worker spawn. PHP-FPM workers are long-lived (they handle many requests), so `getenv()` values are fixed for the worker's lifetime.

**To pick up rotated secrets:**

```bash
# Re-generate the .env file
bella secrets get -o .env

# Reload PHP-FPM workers (graceful — no downtime)
systemctl reload php-fpm
```

After reload, new workers read the updated `.env` file. For automatic rotation, consider using Laravel's or Symfony's configuration caching with a rotation webhook that triggers a `php-fpm reload`.
