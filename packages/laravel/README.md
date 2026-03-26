# bella-baxter/laravel

Laravel integration for the [Bella Baxter](https://bella-baxter.io) secret management platform.

[![Packagist](https://img.shields.io/packagist/v/bella-baxter/laravel)](https://packagist.org/packages/bella-baxter/laravel)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Automatically loads your Bella Baxter secrets into Laravel's environment at boot — secrets are available everywhere via `env()`, `config()`, and `$_ENV`.

## Installation

```bash
composer require bella-baxter/laravel
```

The `BellaServiceProvider` is auto-discovered by Laravel (no manual registration needed).

## Configuration

Publish the config file:

```bash
php artisan vendor:publish --tag=bella-config
```

Set environment variables (or edit `config/bella.php`):

```env
BELLA_BAXTER_URL=https://api.bella-baxter.io
BELLA_BAXTER_API_KEY=bax-your-api-key
BELLA_BAXTER_AUTO_LOAD=true
```

## Usage

### Auto-load (default)

With `auto_load: true` (the default), all secrets are injected into `$_ENV` and `putenv()` at boot:

```php
// In any controller, service, or config file:
$dbUrl = env('DATABASE_URL');         // from Bella Baxter
$apiKey = env('STRIPE_API_KEY');      // from Bella Baxter
```

### Facade

```php
use BellaBaxter\Laravel\Facades\Bella;

$secrets = Bella::getAllSecrets();   // array<string, string>
$value   = Bella::getSecret('DATABASE_URL');
```

### Dependency Injection

```php
use BellaBaxter\BaxterClient;

class MyService
{
    public function __construct(private BaxterClient $bella) {}

    public function doSomething(): void
    {
        $secrets = $this->bella->getAllSecrets();
    }
}
```

## Configuration Reference

| Key | Env var | Default | Description |
|-----|---------|---------|-------------|
| `url` | `BELLA_BAXTER_URL` | `https://api.bella-baxter.io` | Base URL of the Baxter API |
| `api_key` | `BELLA_BAXTER_API_KEY` | `''` | API key from `bella apikeys create` |
| `auto_load` | `BELLA_BAXTER_AUTO_LOAD` | `true` | Load secrets into `$_ENV` at boot |

## Manual registration (Laravel 10 or explicit)

If auto-discovery is disabled, add to `config/app.php`:

```php
'providers' => [
    // ...
    BellaBaxter\Laravel\BellaServiceProvider::class,
],
'aliases' => [
    // ...
    'Bella' => BellaBaxter\Laravel\Facades\Bella::class,
],
```

Or in `bootstrap/providers.php` (Laravel 11+):

```php
return [
    // ...
    BellaBaxter\Laravel\BellaServiceProvider::class,
];
```
