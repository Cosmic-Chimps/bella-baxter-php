# Bella Baxter PHP SDK

Official PHP SDK for the [Bella Baxter](https://github.com/cosmic-chimps/bella-baxter) secret management platform.

## Requirements

- PHP 8.1+
- Extensions: `ext-curl`, `ext-json`, `ext-openssl` (all bundled by default)

## Installation

```bash
composer require bella-baxter/sdk
```

## Quick Start

```php
use BellaBaxter\BaxterClient;
use BellaBaxter\BaxterClientOptions;

$client = new BaxterClient(new BaxterClientOptions(
    baxterUrl: 'https://baxter.example.com',
    apiKey:    getenv('BELLA_BAXTER_API_KEY'), // bax-<keyId>-<secret>, from: bella apikeys create
));

// Project and environment come from the API key itself (GET /api/v1/keys/me).
$secrets = $client->getAllSecrets();
echo $secrets['DATABASE_URL'];
```

## End-to-End Encryption (E2EE)

E2EE is **always on** — there is no option to enable or disable it. Every client installs
`E2EGuzzleMiddleware`, which:

1. Uses your device key (`privateKey`, or `BELLA_BAXTER_PRIVATE_KEY`, both from `bella auth setup`) or,
   when none is configured, a fresh ephemeral P-256 key pair
2. Sends its public key as the `X-E2E-Public-Key` header on **every read that carries secret values** — the
   seven envelope-required reads of the SDK contract (all secrets, both exports, the provider list, one
   secret, one secret version, the global list), whether issued by `getAllSecrets()` or the Kiota client —
   and on nothing else
3. The server encrypts the response using **ECDH-P256 + HKDF-SHA256 + AES-256-GCM**
4. The SDK decrypts the response transparently and hands on the server's own JSON for that read

Secret values are **never visible in plaintext** — not in server logs, proxies, or network captures.

**Fail closed (#1050).** Once the key has been presented on a read that carries secret values, an answer
that is not a decryptable envelope is refused with `BellaBaxter\E2EEResponseException`, never returned:
`getErrorCode()` is `e2ee-plaintext-response` for plain secrets (a header-stripping proxy or a server
regression) and `e2ee-decryption-failed` for an envelope that is malformed, tampered or encrypted to
another key. There is no plaintext fallback.

```php
use BellaBaxter\BaxterClient;
use BellaBaxter\BaxterClientOptions;
use BellaBaxter\E2EEResponseException;

// ZKE: present a registered device key instead of an ephemeral one.
$client = new BaxterClient(new BaxterClientOptions(
    apiKey:     getenv('BELLA_BAXTER_API_KEY'),
    privateKey: getenv('BELLA_BAXTER_PRIVATE_KEY') ?: null, // PKCS#8 PEM; this env var is also read automatically
    onWrappedDekReceived: function (string $wrappedDek, ?string $leaseExpires): void {
        // ZKE key wrapping: persist the wrapped DEK for offline use
    },
));

try {
    $secrets = $client->getAllSecrets();
} catch (E2EEResponseException $e) {
    error_log('refused: ' . $e->getErrorCode()); // never the body or any key
    throw $e;
}
```

## API

### `getAllSecrets(): array<string,string>`

Fetches all secrets for the project + environment the API key is scoped to.

```php
$secrets = $client->getAllSecrets();
// ['DATABASE_URL' => 'postgres://...', 'API_KEY' => '...']
```

### `getSecretsVersion(): array`

Lightweight change check — returns `environmentSlug`, `version` and `lastModified`, no values.

```php
$version = $client->getSecretsVersion();
if ($version['version'] !== $lastSeenVersion) {
    $secrets = $client->getAllSecrets();
}
```

### `getKeyContext(): array`

The project and environment the API key is scoped to (`GET /api/v1/keys/me`).

```php
$ctx = $client->getKeyContext();
echo $ctx['projectSlug'] . '/' . $ctx['environmentSlug'];
```

### `getClient()` and `getRequestAdapter()` — the full API

`getClient()` is the Kiota-generated client for every other endpoint; its requests go through the same
signing and E2EE middleware. Five of the seven value-carrying reads are declared as `E2EEncryptedPayload` in
the OpenAPI document, which is not the shape they decrypt to, so read those through `getRequestAdapter()`
as a raw body:

```php
use Psr\Http\Message\StreamInterface;

$info = $client->getClient()->api()->v1()->projects()->byId('my-app')
    ->environments()->byEnvSlug('production')->providers()->byProviderSlug('vault')
    ->secrets()->byKey('DATABASE_URL')->toGetRequestInformation();

$item = json_decode(
    (string) $client->getRequestAdapter()->sendPrimitiveAsync($info, StreamInterface::class)->wait(),
    true,
);
echo $item['value'];
```

## Configuration

`BaxterClientOptions` constructor arguments (use named arguments):

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `baxterUrl` | `string` | `https://api.bella-baxter.io` | Base URL of the Baxter API |
| `apiKey` | `string` | — (required) | Bella Baxter API key, `bax-<keyId>-<secret>` |
| `timeoutSeconds` | `int` | `10` | HTTP request timeout |
| `privateKey` | `?string` | `null` | PKCS#8 PEM device key (ZKE); falls back to `BELLA_BAXTER_PRIVATE_KEY` |
| `onWrappedDekReceived` | `?callable` | `null` | `(string $wrappedDek, ?string $leaseExpires): void`, called on `X-Bella-Wrapped-Dek` |

## Laravel Integration

```php
// config/services.php
return [
    'bella' => [
        'url'     => env('BELLA_BAXTER_URL', 'https://api.bella-baxter.io'),
        'api_key' => env('BELLA_BAXTER_API_KEY'),
    ],
];
```

```php
// AppServiceProvider::register()
$this->app->singleton(BaxterClient::class, function () {
    return new BaxterClient(new BaxterClientOptions(
        baxterUrl: config('services.bella.url'),
        apiKey:    config('services.bella.api_key'),
    ));
});
```

## Symfony Integration

```yaml
# config/services.yaml
BellaBaxter\BaxterClientOptions:
    arguments:
        $baxterUrl: '%env(BELLA_BAXTER_URL)%'
        $apiKey:    '%env(BELLA_BAXTER_API_KEY)%'

BellaBaxter\BaxterClient:
    arguments:
        $options: '@BellaBaxter\BaxterClientOptions'
```

---

## Typed Secret Code Generation

`bella secrets generate php` fetches the secrets manifest (key names + type hints, no values) from the Bella API and generates a typed `AppSecrets` class. Each method calls `getenv()` at runtime — no secret values are ever embedded in the generated file.

```bash
bella secrets generate php \
  --project my-app \
  --environment production \
  --output AppSecrets.php
```

**Generated `AppSecrets.php`:**

```php
<?php
// Auto-generated by bella secrets generate php — do not edit manually.

class AppSecrets
{
    public function getDatabaseUrl(): string
    {
        $v = getenv('DATABASE_URL');
        if ($v === false) throw new \RuntimeException("Secret 'DATABASE_URL' is not set.");
        return $v;
    }

    public function getPort(): int
    {
        $v = getenv('PORT');
        if ($v === false) throw new \RuntimeException("Secret 'PORT' is not set.");
        return (int) $v;
    }

    public function isEnableFeatureX(): bool
    {
        $v = getenv('ENABLE_FEATURE_X');
        if ($v === false) throw new \RuntimeException("Secret 'ENABLE_FEATURE_X' is not set.");
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
```

### Usage alongside the SDK

```php
// Secrets must be in the environment before accessing.
// Use bella run, the SDK (BaxterClient), or a .env file loaded at bootstrap.

$secrets = new AppSecrets();
$dbUrl = $secrets->getDatabaseUrl();  // string — throws if missing
$port  = $secrets->getPort();         // int — parsed automatically
```

Because each method reads `getenv()` on every call, values updated between requests (or via `bella watch`) are always current.

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `-p, --project <slug>` | `.bella` context | Project slug |
| `-e, --environment <slug>` | `.bella` context | Environment slug |
| `--provider <slug>` | `default` | Provider slug |
| `-o, --output <path>` | `AppSecrets.php` | Output file path |
| `--class-name <name>` | `AppSecrets` | Class name |
| `--dry-run` | — | Print to stdout without writing |
