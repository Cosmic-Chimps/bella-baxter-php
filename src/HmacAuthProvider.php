<?php

declare(strict_types=1);

namespace BellaBaxter;

use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\RequestInformation;
use Http\Promise\FulfilledPromise;
use Http\Promise\Promise;

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

    public function authenticateRequest(RequestInformation $request, array $additionalAuthenticationContext = []): Promise
    {
        $uri       = $request->getUri();
        $path      = parse_url($uri, PHP_URL_PATH) ?? '/';
        $rawQuery  = parse_url($uri, PHP_URL_QUERY) ?? '';
        $query     = $this->sortedQuery($rawQuery);
        $method    = strtoupper((string) $request->httpMethod);
        $body      = '';
        if ($request->content !== null) {
            $body = (string) $request->content; // a PSR-7 stream: __toString() rewinds and reads it
        }
        $timestamp    = gmdate('Y-m-d\TH:i:s\Z');
        $bodyHash     = hash('sha256', $body);
        $stringToSign = "{$method}\n{$path}\n{$query}\n{$timestamp}\n{$bodyHash}";
        $signature    = hash_hmac('sha256', $stringToSign, hex2bin($this->signingSecret));

        // RequestInformation::$headers is private: through the accessor, or every call made with the Kiota
        // client (getClient(), getSecretsVersion()) dies with "Cannot access private property" (#1162).
        $request->addHeader('X-Bella-Key-Id',    $this->keyId);
        $request->addHeader('X-Bella-Timestamp', $timestamp);
        $request->addHeader('X-Bella-Signature', $signature);
        $request->addHeader('X-Bella-Client',    $this->bellaClient);
        if ($this->appClient !== null) {
            $request->addHeader('X-App-Client', $this->appClient);
        }

        // The interface's own promise type: a Guzzle promise here was a TypeError on every Kiota call (#1162).
        return new FulfilledPromise(null);
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
