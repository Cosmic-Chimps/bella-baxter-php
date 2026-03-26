<?php

declare(strict_types=1);

namespace BellaBaxter;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Microsoft\Kiota\Http\GuzzleRequestAdapter;
use BellaBaxter\Generated\BellaClient as KiotaBellaClient;

/**
 * Bella Baxter PHP SDK client.
 *
 * Authenticates with the Baxter API using a bax- API key (HMAC-SHA256 signing). End-to-end
 * encryption (E2EE) is always enabled — secret values are never visible in plaintext over
 * the wire. All HTTP is handled by the Kiota-generated client backed by Guzzle.
 *
 * Quick start:
 * ```php
 * $client = new BaxterClient(new BaxterClientOptions(
 *     baxterUrl: 'https://api.bella-baxter.io',
 *     apiKey:    'bax-abc123-...',
 * ));
 *
 * $secrets = $client->getAllSecrets();
 * echo $secrets['DATABASE_URL'];
 * ```
 *
 * Prerequisites: run `apps/sdk/generate.sh` to generate `src/generated/` first.
 */
final class BaxterClient
{
    private readonly KiotaBellaClient $kiota;
    private readonly GuzzleClient $guzzle;
    private readonly string $baseUrl;
    private readonly string $keyId;
    private readonly string $signingSecret;
    private ?array $keyContext = null;

    public function __construct(BaxterClientOptions $options)
    {
        $this->baseUrl = rtrim($options->baxterUrl, '/');

        // Parse key parts for raw HMAC signing
        $parts = explode('-', $options->apiKey, 3);
        if (count($parts) !== 3 || $parts[0] !== 'bax') {
            throw new \InvalidArgumentException('apiKey must be in format bax-{keyId}-{signingSecret}');
        }
        $this->keyId         = $parts[1];
        $this->signingSecret = $parts[2];

        $auth = new HmacAuthProvider($options->apiKey);

        $stack = HandlerStack::create();
        $stack->push(new E2EGuzzleMiddleware());

        $this->guzzle = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'handler'  => $stack,
            'timeout'  => $options->timeoutSeconds,
            'headers'  => [
                'User-Agent'     => 'bella-php-sdk/1.0',
                'X-Bella-Client' => 'bella-php-sdk',
            ],
        ]);

        $adapter = new GuzzleRequestAdapter($auth, null, null, $this->guzzle);
        $adapter->setBaseUrl($this->baseUrl);

        $this->kiota = new KiotaBellaClient($adapter);
    }

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Discover project + environment from the API key.
     * Calls GET /api/v1/keys/me — the key is already scoped to a project + environment.
     *
     * @return array{keyId:string,role:string,projectSlug:string,environmentSlug:string,...}
     */
    public function getKeyContext(): array
    {
        $path      = '/api/v1/keys/me';
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $bodyHash  = hash('sha256', '');
        $sts       = "GET\n{$path}\n\n{$timestamp}\n{$bodyHash}";
        $signature = hash_hmac('sha256', $sts, hex2bin($this->signingSecret));

        $resp = $this->guzzle->get($this->baseUrl . $path, [
            'headers' => [
                'Accept'             => 'application/json',
                'X-Bella-Key-Id'     => $this->keyId,
                'X-Bella-Timestamp'  => $timestamp,
                'X-Bella-Signature'  => $signature,
            ],
        ]);

        return json_decode((string) $resp->getBody(), true);
    }

    /**
     * Fetch all secrets for the environment derived from the API key.
     * Uses direct HMAC-signed Guzzle request (bypasses Kiota for reliability).
     * E2EE decryption is handled transparently by the Guzzle middleware.
     *
     * @return array<string,string> Map of secret key → value.
     */
    public function getAllSecrets(): array
    {
        $ctx  = $this->getContext();
        $path = sprintf(
            '/api/v1/projects/%s/environments/%s/secrets',
            rawurlencode($ctx['projectSlug']),
            rawurlencode($ctx['environmentSlug'])
        );

        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $bodyHash  = hash('sha256', '');
        $sts       = "GET\n{$path}\n\n{$timestamp}\n{$bodyHash}";
        $signature = hash_hmac('sha256', $sts, hex2bin($this->signingSecret));

        $resp = $this->guzzle->get($this->baseUrl . $path, [
            'headers' => [
                'Accept'            => 'application/json',
                'X-Bella-Key-Id'    => $this->keyId,
                'X-Bella-Timestamp' => $timestamp,
                'X-Bella-Signature' => $signature,
            ],
        ]);

        $data    = json_decode((string) $resp->getBody(), true);
        $secrets = $data['secrets'] ?? [];
        return array_map(static function ($v): string {
            if (is_bool($v)) return $v ? 'true' : 'false';
            return (string) $v;
        }, $secrets);
    }

    /**
     * Lightweight version check — returns version metadata only.
     *
     * @return array{environmentSlug:string,version:int,lastModified:string|null}
     */
    public function getSecretsVersion(): array
    {
        $ctx = $this->getContext();
        $resp = $this->kiota->api()->v1()->projects()->byProjectRef($ctx['projectSlug'])
            ->environments()->byEnvSlug($ctx['environmentSlug'])->secrets()->version()->get();

        return [
            'environmentSlug' => $resp->getEnvironmentSlug(),
            'version'         => $resp->getVersion(),
            'lastModified'    => $resp->getLastModified()?->format(\DateTime::ATOM),
        ];
    }

    /**
     * Access the full Kiota API navigator for all other endpoints
     * (TOTP, projects, providers, environments, etc.).
     *
     * Example:
     * ```php
     * $client->getClient()->api()->v1()->projects()->byProjectRef('my-app')
     *     ->environments()->byEnvSlug('prod')->totp()->get();
     * ```
     */
    public function getClient(): KiotaBellaClient
    {
        return $this->kiota;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getContext(): array
    {
        if ($this->keyContext === null) {
            $this->keyContext = $this->getKeyContext();
        }
        return $this->keyContext;
    }
}
