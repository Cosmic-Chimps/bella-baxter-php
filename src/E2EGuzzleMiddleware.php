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
 *
 * #1050 — once the key has been presented on a read that carries secret values
 * ({@see requiresEnvelope()}), a `2xx` answer that is not a decryptable envelope is REFUSED with
 * {@see E2EEResponseException}: `e2ee-plaintext-response` for plain secrets, `e2ee-decryption-failed`
 * for an envelope that is malformed, tampered or encrypted to another key. There is no plaintext
 * fallback (apps/sdk/SDK_CONTRACT.md, "Rule: a presented key requires an envelope").
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

    /**
     * Whether a `2xx` answer to this request must be an E2EE envelope when the key was presented: the
     * `GET`s that carry secret VALUES, which the server always encrypts to a presented key
     * (SDK_CONTRACT.md, "Which Endpoints Support E2EE"). Everything else — writes, `…/secrets/version`,
     * `…/hash`, `…/{key}/metadata`, `…/{key}/versions` — is plain JSON by design and is not required to be.
     */
    public static function requiresEnvelope(string $method, string $path): bool
    {
        if (strtoupper($method) !== 'GET') {
            return false;
        }
        $marker = '/api/v1/projects/';
        $at     = strpos($path, $marker);
        if ($at === false) {
            return false;
        }
        $rest = array_slice(explode('/', substr($path, $at + strlen($marker))), 1); // drop {project}
        $n    = count($rest);

        if ($rest === ['secrets']) {
            return true; // listGlobalSecrets
        }
        if ($n < 3 || $rest[0] !== 'environments' || $rest[2] === '') {
            return false;
        }
        if ($rest[2] === 'secrets') {
            // getAllEnvironmentSecrets, exportEnvironmentSecrets
            return $n === 3 || ($n === 4 && $rest[3] === 'export');
        }
        if ($rest[2] !== 'providers' || $n < 5 || $rest[4] !== 'secrets') {
            return false;
        }
        return match ($n) {
            5       => true,                                   // listSecrets
            6       => $rest[5] !== '' && $rest[5] !== 'hash',  // getSecret, exportSecrets
            8       => $rest[5] !== '' && $rest[6] === 'versions' && ctype_digit($rest[7]), // getSecretVersion
            default => false,
        };
    }

    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $path         = $request->getUri()->getPath();
            $isSecretsGet = str_ends_with($path, '/secrets') && strtoupper($request->getMethod()) === 'GET';

            // The key is presented on exactly these requests; the envelope rule only binds where it was.
            if ($isSecretsGet) {
                $request = $request->withHeader('X-E2E-Public-Key', $this->e2ee->publicKeyBase64);
            }
            $required = $isSecretsGet && self::requiresEnvelope($request->getMethod(), $path);

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($isSecretsGet, $required, $path): ResponseInterface {
                    if (!$isSecretsGet || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                        return $response;
                    }
                    $body = (string) $response->getBody();

                    // A presented key on a path that does not carry values: decrypt an envelope if one came
                    // back, otherwise the plain answer is the legitimate one.
                    if (!$required && E2EEncryption::envelopeOf($body) === null) {
                        return $response->withBody(Utils::streamFor($body));
                    }

                    // Throws E2EEResponseException — plaintext or an envelope that does not decrypt is refused.
                    $plainJson = $this->e2ee->decryptRaw($body, $path);
                    try {
                        $parsed = json_decode($plainJson, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        throw E2EEResponseException::decryptionFailed($path, $e);
                    }
                    if (!is_array($parsed)) {
                        throw E2EEResponseException::decryptionFailed($path);
                    }

                    // If the server sent a full AllEnvironmentSecretsResponse, pass it through directly
                    // so that environmentSlug, version, lastModified etc. are preserved.
                    if (isset($parsed['secrets']) && is_array($parsed['secrets'])) {
                        $newBody = $plainJson;
                    } else {
                        // Legacy format — synthesise a response wrapper.
                        $newBody = json_encode(
                            ['secrets' => E2EEncryption::secretsFromPlaintext($parsed), 'version' => 0, 'environmentSlug' => '', 'environmentName' => '', 'lastModified' => ''],
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
