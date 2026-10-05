<?php

declare(strict_types=1);

namespace BellaBaxter\Tests;

use BellaBaxter\E2EEncryption;
use BellaBaxter\E2EEResponseException;
use BellaBaxter\E2EGuzzleMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * #1050 (b) — once the SDK has presented its E2EE key on a read that carries secret values, a 2xx
 * answer that is not a decryptable envelope is REFUSED (apps/sdk/SDK_CONTRACT.md, "Rule: a presented
 * key requires an envelope"). The stub is a misbehaving server behind the real middleware: it answers
 * the key the request actually presented, so the valid case proves the presented key is the one used.
 *
 * Keys are generated per test, so no key material is committed.
 */
final class PresentedKeyRequiresEnvelopeTest extends TestCase
{
    private const SECRETS_PATH = '/api/v1/projects/contract-project/environments/contract-env/secrets';
    private const SENTINEL     = 'the-registered-device-key-was-used';

    /** @var list<RequestInterface> */
    private array $seen = [];

    /** @param callable(RequestInterface): Response $answer */
    private function client(callable $answer): Client
    {
        $mock = new MockHandler([function (RequestInterface $request) use ($answer): Response {
            $this->seen[] = $request;
            return $answer($request);
        }]);
        $stack = HandlerStack::create($mock);
        $stack->push(new E2EGuzzleMiddleware());
        return new Client(['handler' => $stack, 'base_uri' => 'http://stub.invalid']);
    }

    private static function plaintext(): string
    {
        return json_encode([
            'environmentSlug' => 'contract-env',
            'environmentName' => 'contract-env',
            'secrets'         => ['BELLA_KEY_CONTRACT' => self::SENTINEL],
            'version'         => 1,
            'lastModified'    => '2026-10-04T00:00:00Z',
        ], JSON_THROW_ON_ERROR);
    }

    /** The server side of the contract (EciesAlgorithm.Encrypt / stub encryptFor), in PHP. */
    public static function encryptFor(string $clientSpkiB64, string $plaintext): array
    {
        $clientPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split($clientSpkiB64, 64, "\n") . "-----END PUBLIC KEY-----\n";
        $clientKey = openssl_pkey_get_public($clientPem);
        self::assertNotFalse($clientKey, 'the presented key is SPKI P-256');

        $ephemeral = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $shared    = openssl_pkey_derive($clientKey, $ephemeral);
        $aesKey    = hash_hkdf('sha256', $shared, 32, 'bella-e2ee-v1', str_repeat("\x00", 32));
        $nonce     = random_bytes(12);
        $tag       = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        $serverPem = openssl_pkey_get_details($ephemeral)['key'];
        $serverDer = preg_replace('/-----[A-Z ]+-----|\s/', '', $serverPem);

        return [
            'encrypted'       => true,
            'algorithm'       => 'ECDH-P256-HKDF-SHA256-AES256GCM',
            'serverPublicKey' => $serverDer,
            'nonce'           => base64_encode($nonce),
            'tag'             => base64_encode($tag),
            'ciphertext'      => base64_encode($ciphertext),
        ];
    }

    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
    }

    private static function presented(RequestInterface $request): string
    {
        $key = $request->getHeaderLine('X-E2E-Public-Key');
        self::assertNotSame('', $key, 'the middleware presented its key');
        return $key;
    }

    private function assertRefused(Client $client, string $code): void
    {
        try {
            $body = (string) $client->get(self::SECRETS_PATH)->getBody();
            self::fail('accepted the answer after presenting its key: ' . $body);
        } catch (E2EEResponseException $e) {
            self::assertSame($code, $e->getErrorCode());
            self::assertSame($code, $e->errorCode);
            self::assertStringContainsString($code, $e->getMessage());
            self::assertStringContainsString(self::SECRETS_PATH, $e->getMessage());
            self::assertStringNotContainsString(self::SENTINEL, $e->getMessage());
        }
        self::assertNotSame('', $this->seen[0]->getHeaderLine('X-E2E-Public-Key'));
    }

    // (1) a genuine envelope to the presented key is decrypted and returned
    public function testValidEnvelopeToThePresentedKeyIsDecrypted(): void
    {
        $client = $this->client(fn (RequestInterface $r) => self::json(self::encryptFor(self::presented($r), self::plaintext())));

        $data = json_decode((string) $client->get(self::SECRETS_PATH)->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(self::SENTINEL, $data['secrets']['BELLA_KEY_CONTRACT']);
        self::assertSame('contract-env', $data['environmentSlug']);
    }

    // (2) plain secrets to a presented key are refused, and the sentinel never reaches the caller
    public function testPlaintextAfterPresentingTheKeyIsRefused(): void
    {
        $client = $this->client(fn (RequestInterface $r) => self::json(json_decode(self::plaintext(), true)));
        $this->assertRefused($client, E2EEResponseException::PLAINTEXT_RESPONSE);
    }

    public function testNonJsonAfterPresentingTheKeyIsRefusedAsPlaintext(): void
    {
        $client = $this->client(fn () => new Response(200, ['Content-Type' => 'text/plain'], 'BELLA_KEY_CONTRACT=' . self::SENTINEL));
        $this->assertRefused($client, E2EEResponseException::PLAINTEXT_RESPONSE);
    }

    public function testEncryptedFalseIsRefusedAsPlaintext(): void
    {
        $client = $this->client(fn () => self::json(['encrypted' => false] + json_decode(self::plaintext(), true)));
        $this->assertRefused($client, E2EEResponseException::PLAINTEXT_RESPONSE);
    }

    // (3) a tampered envelope (one ciphertext byte flipped, so the GCM tag fails) is refused
    public function testTamperedEnvelopeIsRefused(): void
    {
        $client = $this->client(function (RequestInterface $r) {
            $envelope = self::encryptFor(self::presented($r), self::plaintext());
            $bytes    = base64_decode($envelope['ciphertext']);
            $bytes[0] = chr(ord($bytes[0]) ^ 0x01);
            $envelope['ciphertext'] = base64_encode($bytes);
            return self::json($envelope);
        });
        $this->assertRefused($client, E2EEResponseException::DECRYPTION_FAILED);
    }

    // (4) an envelope encrypted to a different key is refused
    public function testEnvelopeToAnotherKeyIsRefused(): void
    {
        $other  = (new E2EEncryption())->publicKeyBase64;
        $client = $this->client(fn () => self::json(self::encryptFor($other, self::plaintext())));
        $this->assertRefused($client, E2EEResponseException::DECRYPTION_FAILED);
    }

    public function testMalformedEnvelopeIsRefused(): void
    {
        $client = $this->client(fn () => self::json(['encrypted' => true, 'nonce' => 'not base64!']));
        $this->assertRefused($client, E2EEResponseException::DECRYPTION_FAILED);
    }

    // (5) a request that does not carry values answers plain JSON by design, and passes through
    public function testWriteAnsweredInPlainJsonPassesThrough(): void
    {
        $client = $this->client(fn () => self::json(['key' => 'K', 'created' => true], 201));

        $resp = $client->post(self::SECRETS_PATH, ['json' => ['key' => 'K', 'value' => 'V']]);

        self::assertSame(201, $resp->getStatusCode());
        self::assertSame(['key' => 'K', 'created' => true], json_decode((string) $resp->getBody(), true));
    }

    public function testVersionCheckAnsweredInPlainJsonPassesThrough(): void
    {
        $client = $this->client(fn () => self::json(['environmentSlug' => 'contract-env', 'version' => 7]));

        $resp = $client->get(self::SECRETS_PATH . '/version');

        self::assertSame(7, json_decode((string) $resp->getBody(), true)['version']);
    }

    // (6) a non-2xx answer is the API's own error, not this one
    public function testNon2xxIsNotTurnedIntoAnE2EEError(): void
    {
        $client = $this->client(fn () => self::json(['type' => 'contract-key-mismatch', 'title' => 'no'], 403));

        $this->expectException(ClientException::class);
        $client->get(self::SECRETS_PATH);
    }

    public function testDecryptHelperRefusesPlaintextToo(): void
    {
        $this->expectException(E2EEResponseException::class);
        $this->expectExceptionMessage(E2EEResponseException::PLAINTEXT_RESPONSE);
        (new E2EEncryption())->decrypt(self::plaintext());
    }

    /** The one rule every SDK implements: which reads must come back as an envelope. */
    public function testRequiresEnvelopeMatchesExactlyTheValueCarryingReads(): void
    {
        $p = '/api/v1/projects/p/environments/e';
        foreach ([
            '/api/v1/projects/p/secrets',
            "$p/secrets",
            "$p/secrets/export",
            "$p/providers/v/secrets",
            "$p/providers/v/secrets/export",
            "$p/providers/v/secrets/MY_KEY",
            "$p/providers/v/secrets/MY_KEY/versions/3",
            '/gateway/api/v1/projects/p/secrets',
        ] as $path) {
            self::assertTrue(E2EGuzzleMiddleware::requiresEnvelope('GET', $path), $path);
            self::assertFalse(E2EGuzzleMiddleware::requiresEnvelope('POST', $path), "POST $path");
        }
        foreach ([
            "$p/secrets/version",
            "$p/secrets/manifest",
            "$p/secrets/certificates",
            "$p/providers/v/secrets/hash",
            "$p/providers/v/secrets/MY_KEY/metadata",
            "$p/providers/v/secrets/MY_KEY/versions",
            "$p/providers/v/secrets/MY_KEY/versions/latest",
            "$p/providers/v/secrets/MY_KEY/rotation-policy",
            "$p/providers/v/secrets/import/preview",
            '/api/v1/tenants/me/zke',
            '/api/v1/projects/p',
        ] as $path) {
            self::assertFalse(E2EGuzzleMiddleware::requiresEnvelope('GET', $path), $path);
        }
    }
}
