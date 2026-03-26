# bella-baxter/symfony

Symfony Bundle for the [Bella Baxter](https://bella-baxter.io) secret management platform.

[![Packagist](https://img.shields.io/packagist/v/bella-baxter/symfony)](https://packagist.org/packages/bella-baxter/symfony)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Registers `BaxterClient` as a Symfony service and loads secrets into `$_ENV` / `putenv()` on the first HTTP request.

## Installation

```bash
composer require bella-baxter/symfony
```

## Registration

Add to `config/bundles.php`:

```php
return [
    // ...
    BellaBaxter\Symfony\BellaBundle::class => ['all' => true],
];
```

## Configuration

Create `config/packages/bella.yaml`:

```yaml
bella:
  url: '%env(BELLA_BAXTER_URL)%'
  api_key: '%env(BELLA_BAXTER_API_KEY)%'
  auto_load: true    # optional, default: true
```

Set environment variables in `.env`:

```env
BELLA_BAXTER_URL=https://api.bella-baxter.io
BELLA_BAXTER_API_KEY=bax-your-api-key
```

## Usage

### Auto-load (default)

With `auto_load: true`, secrets are injected into `$_ENV` on the first request:

```php
// In any controller or service:
$dbUrl = $_ENV['DATABASE_URL'];       // from Bella Baxter
$dbUrl = getenv('DATABASE_URL');      // same
$dbUrl = $_SERVER['DATABASE_URL'];    // same
```

### Service injection

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

### Autowiring

`BaxterClient` is registered as a public service and autowired by type — no manual service definition needed.

## Configuration reference

| Key | Default | Description |
|-----|---------|-------------|
| `url` | — | Base URL of the Baxter API |
| `api_key` | — | API key from `bella apikeys create` |
| `auto_load` | `true` | Load secrets into `$_ENV` on first request |
