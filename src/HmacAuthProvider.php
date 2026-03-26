<?php

declare(strict_types=1);

namespace BellaBaxter;

use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\RequestInformation;
use GuzzleHttp\Promise\Create;
use React\Promise\PromiseInterface;

/**
 * Kiota AuthenticationProvider that signs every request with HMAC-SHA256.
 *
 * Reads the bax-{keyId}-{signingSecret} API key and adds:
 *   X-Bella-Key-Id, X-Bella-Timestamp, X-Bella-Signature
 */
final class HmacAuthProvider implements AuthenticationProvider
{
    private readonly string $keyId;
    private readonly string $signingSecret;
    private readonly string $bellaClient;
    private readonly ?string $appClient;

    public function __construct(string $apiKey, string $bellaClient = 'bella-php-sdk', ?string $appClient = null)
    {
        $parts = explode('-', $apiKey, 3);
        if (count($parts) !== 3 || $parts[0] !== 'bax') {
            throw new \InvalidArgumentException('apiKey must be in format bax-{keyId}-{signingSecret}');
        }
        $this->keyId         = $parts[1];
        $this->signingSecret = $parts[2];
        $this->bellaClient   = $bellaClient;
        $this->appClient     = $appClient ?? getenv('BELLA_BAXTER_APP_CLIENT') ?: null;
    }

    public function authenticateRequest(RequestInformation $request, array $additionalAuthenticationContext = []): \Http\Promise\Promise
    {
        $uri       = $request->getUri();
        $path      = parse_url($uri, PHP_URL_PATH) ?? '/';
        $rawQuery  = parse_url($uri, PHP_URL_QUERY) ?? '';
        $query     = $this->sortedQuery($rawQuery);
        $method    = strtoupper((string) $request->httpMethod);
        $body      = '';
        if ($request->content !== null) {
            $body = is_string($request->content) ? $request->content : (string) stream_get_contents($request->content);
        }
        $timestamp    = gmdate('Y-m-d\TH:i:s\Z');
        $bodyHash     = hash('sha256', $body);
        $stringToSign = "{$method}\n{$path}\n{$query}\n{$timestamp}\n{$bodyHash}";
        $signature    = hash_hmac('sha256', $stringToSign, hex2bin($this->signingSecret));

        $request->headers->add('X-Bella-Key-Id',    $this->keyId);
        $request->headers->add('X-Bella-Timestamp', $timestamp);
        $request->headers->add('X-Bella-Signature', $signature);
        $request->headers->add('X-Bella-Client',    $this->bellaClient);
        if ($this->appClient !== null) {
            $request->headers->add('X-App-Client', $this->appClient);
        }

        return Create::promiseFor(null);
    }

    private function sortedQuery(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        parse_str($raw, $params);
        ksort($params);
        return http_build_query($params);
    }
}
