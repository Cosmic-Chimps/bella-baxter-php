<?php

declare(strict_types=1);

namespace BellaBaxter;

use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Guzzle middleware that transparently adds E2EE to the reads that carry secret values.
 *
 * On outbound: adds X-E2E-Public-Key to EVERY envelope-required read ({@see requiresEnvelope()}) — the
 *              seven GETs of apps/sdk/SDK_CONTRACT.md, whichever call issued them — and to nothing else
 *              (#1162, "Rule: the key is presented on every envelope-required read").
 * On inbound:  decrypts the envelope and hands the plaintext on UNCHANGED — the JSON the server sends
 *              without a key (an AllEnvironmentSecretsResponse, a {key: value} export, an array of secret
 *              items, one item, …), so the caller sees the read's real shape.
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
            $path = $request->getUri()->getPath();

            // #1162 — the key is presented on exactly the envelope-required reads, so every read that
            // carries secret values is end-to-end encrypted and the #1050 rule binds on each of them.
            $presented = self::requiresEnvelope($request->getMethod(), $path);
            if ($presented) {
                $request = $request->withHeader('X-E2E-Public-Key', $this->e2ee->publicKeyBase64);
            }

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($presented, $path): ResponseInterface {
                    if (!$presented || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                        return $response;
                    }

                    // Throws E2EEResponseException — plaintext or an envelope that does not decrypt is refused.
                    $plainJson = $this->e2ee->decryptRaw((string) $response->getBody(), $path);
                    try {
                        $parsed = json_decode($plainJson, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        throw E2EEResponseException::decryptionFailed($path, $e);
                    }
                    if (!is_array($parsed)) {
                        throw E2EEResponseException::decryptionFailed($path);
                    }

                    // The plaintext is the server's own JSON for this read and is handed on as is (#1162).
                    // The old "legacy" {secrets: …} synthesis turned a getSecret item or a listSecrets array
                    // into a different document that merely decrypted correctly.
                    $response = $response->withBody(Utils::streamFor($plainJson));

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
