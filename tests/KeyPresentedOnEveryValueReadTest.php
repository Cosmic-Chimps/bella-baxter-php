<?php

declare(strict_types=1);

namespace BellaBaxter\Tests;

use BellaBaxter\E2EGuzzleMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * #1162 — the key is presented on EVERY envelope-required read (apps/sdk/SDK_CONTRACT.md, "Rule: the key is
 * presented on every envelope-required read"), and the decrypted body reaches the caller UNCHANGED. Before
 * the fix the middleware presented only on GETs ending in `/secrets`, so getSecret, getSecretVersion and both
 * exports went out without the key, and a decrypted body without a `secrets` object was rebuilt into one.
 */
final class KeyPresentedOnEveryValueReadTest extends TestCase
{
    private const ENV  = '/api/v1/projects/p/environments/e';
    private const PROV = self::ENV . '/providers/v/secrets';
    private const K    = 'BELLA_KEY_CONTRACT';
    private const S    = 'the-presented-key-decrypted-this';

    /** @var list<RequestInterface> */
    private array $seen = [];

    private static function item(): array
    {
        return ['key' => self::K, 'value' => self::S, 'description' => null];
    }

    /** operationId => [request target, the plaintext the API encrypts for it]. */
    public static function valueReads(): array
    {
        return [
            'getAllEnvironmentSecrets' => [self::ENV . '/secrets', [
                'environmentSlug' => 'e', 'environmentName' => 'e', 'secrets' => [self::K => self::S],
                'version' => 1, 'lastModified' => '2026-10-04T00:00:00Z',
            ]],
            'exportEnvironmentSecrets' => [self::ENV . '/secrets/export?format=json', [self::K => self::S]],
            'listSecrets'              => [self::PROV, [self::item()]],
            'exportSecrets'            => [self::PROV . '/export?format=dotenv', [self::K => self::S]],
            'getSecret'                => [self::PROV . '/' . self::K, self::item()],
            'getSecretVersion'         => [self::PROV . '/' . self::K . '/versions/3', self::item()],
            'listGlobalSecrets'        => ['/api/v1/projects/p/secrets', [
                'projectRef' => 'p', 'projectSlug' => 'p', 'globalSecretProviderId' => null, 'secrets' => [self::item()],
            ]],
        ];
    }

    /** A server that encrypts `$plain` to whatever key was presented, and refuses a read without one. */
    private function client(array $plain): Client
    {
        $mock = new MockHandler([function (RequestInterface $request) use ($plain): Response {
            $this->seen[] = $request;
            $key = $request->getHeaderLine('X-E2E-Public-Key');
            if ($key === '') {
                return new Response(403, ['Content-Type' => 'application/json'], '{"type":"zke-key-required"}');
            }
            $envelope = PresentedKeyRequiresEnvelopeTest::encryptFor($key, json_encode($plain, JSON_THROW_ON_ERROR));
            return new Response(200, ['Content-Type' => 'application/json'], json_encode($envelope, JSON_THROW_ON_ERROR));
        }]);
        $stack = HandlerStack::create($mock);
        $stack->push(new E2EGuzzleMiddleware());
        return new Client(['handler' => $stack, 'base_uri' => 'http://stub.invalid', 'http_errors' => true]);
    }

    #[DataProvider('valueReads')]
    public function testTheKeyIsPresentedAndTheBodyHandedOnUnchanged(string $target, array $plain): void
    {
        $body = (string) $this->client($plain)->get($target)->getBody();

        self::assertNotSame('', $this->seen[0]->getHeaderLine('X-E2E-Public-Key'), "key not presented on {$target}");
        self::assertSame($plain, json_decode($body, true, 512, JSON_THROW_ON_ERROR), "{$target} was reshaped");
    }

    public function testTheKeyIsNotPresentedOnAReadThatCarriesNoValue(): void
    {
        $mock = new MockHandler([function (RequestInterface $request): Response {
            $this->seen[] = $request;
            return new Response(200, ['Content-Type' => 'application/json'], '{"environmentSlug":"e","version":7}');
        }]);
        $stack = HandlerStack::create($mock);
        $stack->push(new E2EGuzzleMiddleware());
        $client = new Client(['handler' => $stack, 'base_uri' => 'http://stub.invalid']);

        $resp = $client->get(self::ENV . '/secrets/version');

        self::assertSame('', $this->seen[0]->getHeaderLine('X-E2E-Public-Key'));
        self::assertSame(7, json_decode((string) $resp->getBody(), true)['version']);
    }
}
