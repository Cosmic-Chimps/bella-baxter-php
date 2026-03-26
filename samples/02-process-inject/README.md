# Sample 02: Process Inject (bella run) — PHP

**Pattern:** `bella run -- php app.php` — secrets injected directly as env vars into the PHP process, no file written.

---

## How it works

```
bella run -- php app.php
  ↓
1. bella fetches secrets via E2EE from Baxter API
2. bella spawns: php app.php
3. PHP receives secrets in $_SERVER / $_ENV / getenv()
4. bella exits with PHP's exit code
```

**No `.env` file is written.** Secrets live only in the child process environment — safer than file approach.

## Setup

```bash
# Authenticate with API key
bella login --api-key bax-xxxxxxxxxxxxxxxxxxxx

export BELLA_BAXTER_URL=http://localhost:5522   # your Bella Baxter instance

# Run with secrets injected as environment variables
bella run -- php app.php
```

## Works with any PHP command

```bash
# Laravel
bella run -- php artisan serve

# Symfony
bella run -- php bin/console server:run

# PHP built-in server
bella run -- php -S localhost:3000 index.php

# Artisan commands (migrations, queues, etc.)
bella run -- php artisan migrate --force
bella run -- php artisan queue:work

# PHPUnit (inject test secrets)
bella run -- vendor/bin/phpunit
```

## vs. `.env` file approach

| | `bella secrets get -o .env` | `bella run --` |
|---|---|---|
| Secrets written to disk | ✅ Yes | ❌ No |
| Requires phpdotenv | ✅ Yes | ❌ No |
| Works with any command | ✅ Yes | ✅ Yes |
| Secret security | File system | Memory only |
