# Sample 04: Symfony

**Pattern:** `BellaSecretsLoader` event subscriber loads secrets into `$_ENV` on the first request, available via `getenv()` everywhere in the app.

This is a **complete, runnable Symfony 7** sample project. Clone it, install dependencies, and run.

---

## Quick Start

```bash
# 1. Install dependencies (includes bella-baxter/sdk from local path)
composer install

# 2. Start the server with secrets injected
bella exec -- php -S localhost:8096 public/index.php

# 3. Test the endpoints
curl http://localhost:8096/
curl http://localhost:8096/typed
curl http://localhost:8096/health
```

---

## Endpoints

| Route        | Description                                       |
|--------------|---------------------------------------------------|
| `GET /`      | All 8 secrets via `getenv()` as JSON              |
| `GET /typed` | All 8 secrets via typed `BellaAppSecrets` class   |
| `GET /health`| Health check `{"status":"ok"}`                    |

---

## How it works

**On each request (PHP built-in server):**
1. `bella exec` injects `BELLA_BAXTER_API_KEY` + `BELLA_BAXTER_URL` into the process environment
2. `BellaSecretsLoader::onKernelRequest()` fires at priority 256 (before controllers)
3. `BaxterClient::getAllSecrets()` fetches all secrets
4. Each secret is written to `$_ENV`, `$_SERVER`, and `putenv()`
5. Controller reads secrets via `getenv()`

**Required env vars (provided by `bella exec`):**
```dotenv
BELLA_BAXTER_API_KEY=bax-xxxxxxxxxxxxxxxxxxxx
BELLA_BAXTER_URL=http://localhost:5522
```

---

## Typed secrets

`src/Bella/BellaAppSecrets.php` provides strongly-typed accessors.

In a real project, generate this class automatically:
```bash
bella secrets generate php --app php-04-symfony -o src/Bella/BellaAppSecrets.php
```

---

## Adding to your own Symfony project

1. `composer require bella-baxter/sdk`
2. Copy `src/Bella/BellaSecretsLoader.php` into your project
3. Register in `config/services.yaml`:
   ```yaml
   BellaBaxter\BaxterClientOptions:
     arguments:
       $baxterUrl: '%env(resolve:BELLA_BAXTER_URL)%'
       $apiKey:    '%env(resolve:BELLA_BAXTER_API_KEY)%'

   BellaBaxter\BaxterClient:
     arguments:
       $options: '@BellaBaxter\BaxterClientOptions'

   App\Bella\BellaSecretsLoader:
     tags: [kernel.event_subscriber]
   ```
4. Run with `bella exec --app <your-app> -- php -S localhost:8080 public/index.php`
