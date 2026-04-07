<?php

declare(strict_types=1);

namespace BellaBaxter;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that transparently adds E2EE to GET /secrets requests.
 *
 * On outbound: adds X-E2E-Public-Key header so the server encrypts the response.
 * On inbound:  decrypts the encrypted payload and reconstructs a normal secrets response.
 *              If an $onWrappedDekReceived callback is provided, it is invoked whenever
 *              the server returns an X-Bella-Wrapped-Dek header (ZKE key-wrapping flow).
 */
final class E2EGuzzleMiddleware
{
    private readonly E2EEncryption $e2ee;

    /** @var callable|null */
    private readonly mixed $onWrappedDekReceived;

    /**
     * @param E2EEncryption|null $e2ee                 Pre-loaded encryption instance (ZKE). Generates
     *                                                  an ephemeral key when null (standard E2EE).
     * @param callable|null      $onWrappedDekReceived  Invoked with (string $wrappedDek, ?string $leaseExpires)
     *                                                  when the server returns X-Bella-Wrapped-Dek.
     */
    public function __construct(?E2EEncryption $e2ee = null, ?callable $onWrappedDekReceived = null)
    {
        $this->e2ee                 = $e2ee ?? new E2EEncryption();
        $this->onWrappedDekReceived = $onWrappedDekReceived;
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $path         = $request->getUri()->getPath();
            $isSecretsGet = str_ends_with($path, '/secrets') && strtoupper($request->getMethod()) === 'GET';

            if ($isSecretsGet) {
                $request = $request->withHeader('X-E2E-Public-Key', $this->e2ee->publicKeyBase64);
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($isSecretsGet): ResponseInterface {
                    if (!$isSecretsGet || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                        return $response;
                    }
                    $body      = (string) $response->getBody();
                    $plainJson = $this->e2ee->decryptRaw($body);
                    $parsed    = json_decode($plainJson, true, 512, JSON_THROW_ON_ERROR);

                    // If the server sent a full AllEnvironmentSecretsResponse, pass it through directly
                    // so that environmentSlug, version, lastModified etc. are preserved.
                    if (isset($parsed['secrets']) && is_array($parsed['secrets'])) {
                        $newBody = $plainJson;
                    } else {
                        // Legacy format — synthesise a response wrapper.
                        $secrets = $this->e2ee->decrypt($body);
                        $newBody = json_encode(
                            ['secrets' => $secrets, 'version' => 0, 'environmentSlug' => '', 'environmentName' => '', 'lastModified' => ''],
                            JSON_THROW_ON_ERROR,
                        );
                    }

                    $response = $response->withBody(Utils::streamFor($newBody));

                    // ZKE: notify caller when the server wraps a DEK for the persistent device key.
                    if ($this->onWrappedDekReceived !== null) {
                        $wrappedDek = $response->getHeaderLine('X-Bella-Wrapped-Dek');
                        if ($wrappedDek !== '') {
                            $leaseExpires = $response->getHeaderLine('X-Bella-Lease-Expires') ?: null;
                            ($this->onWrappedDekReceived)($wrappedDek, $leaseExpires);
                        }
                    }

                    return $response;
                }
            );
        };
    }
}
