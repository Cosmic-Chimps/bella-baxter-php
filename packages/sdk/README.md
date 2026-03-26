# Bella Baxter — PHP SDK

Official PHP SDK for the [Bella Baxter](https://bella-baxter.io) secret management platform.

[![Packagist](https://img.shields.io/packagist/v/bella-baxter/sdk)](https://packagist.org/packages/bella-baxter/sdk)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

## Framework integrations

| Framework | Package | Install |
|-----------|---------|---------|
| **Laravel** | [`bella-baxter/laravel`](../laravel/) | `composer require bella-baxter/laravel` |
| **Symfony** | [`bella-baxter/symfony`](../symfony/) | `composer require bella-baxter/symfony` |
| Scripts / custom | **bella-baxter/sdk** (this) | `composer require bella-baxter/sdk` |

## Installation

```bash
composer require bella-baxter/sdk
```

## Quick Start

```php
use BellaBaxter\BaxterClient;
use BellaBaxter\BaxterClientOptions;

$client = new BaxterClient(new BaxterClientOptions(
    baxterUrl: 'https://api.bella-baxter.io',
    apiKey:    'bax-your-api-key',
));

$secrets = $client->getAllSecrets();
echo $secrets['DATABASE_URL'];
```

## API

### `getAllSecrets(): array<string,string>`
Fetches all secrets for the configured project/environment.

### `getSecret(string $key): string`
Returns a single secret by key. Throws `\RuntimeException` if not found.

### `getSecretsVersion(int $version): array<string,string>`
Fetches secrets at a specific version snapshot.

## Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `baxterUrl` | `string` | — | Base URL of the Baxter API |
| `apiKey` | `string` | — | API key from `bella apikeys create` |

## Typed Secret Generation

```bash
bella secrets generate php --output AppSecrets.php
```

See [docs.bella-baxter.io](https://docs.bella-baxter.io) for full reference.
