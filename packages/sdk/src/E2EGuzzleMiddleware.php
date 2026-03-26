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
 */
final class E2EGuzzleMiddleware
{
    private readonly E2EEncryption $e2ee;

    public function __construct()
    {
        $this->e2ee = new E2EEncryption();
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
                    return $response->withBody(Utils::streamFor($newBody));
                }
            );
        };
    }
}
