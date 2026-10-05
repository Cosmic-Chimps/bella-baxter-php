<?php

declare(strict_types=1);

namespace BellaBaxter\Tests;

use BellaBaxter\HmacAuthProvider;
use Http\Promise\Promise;
use Microsoft\Kiota\Abstractions\HttpMethod;
use Microsoft\Kiota\Abstractions\RequestInformation;
use PHPUnit\Framework\TestCase;

/**
 * #1162 — the provider Kiota calls before EVERY generated-client request. It wrote to the private
 * RequestInformation::$headers and returned a Guzzle promise where the interface declares Http\Promise\Promise,
 * so every call through getClient() (and BaxterClient::getSecretsVersion()) failed before leaving the process.
 */
final class HmacAuthProviderTest extends TestCase
{
    public function testSignsTheRequestThroughTheHeaderAccessorAndReturnsTheInterfacePromise(): void
    {
        $info = new RequestInformation();
        $info->httpMethod = HttpMethod::GET;
        $info->setUri('http://stub.invalid/api/v1/projects/p/secrets?b=2&a=1');

        $promise = (new HmacAuthProvider('bax-0123456789abcdef0123456789abcdef-00ff'))->authenticateRequest($info);

        self::assertInstanceOf(Promise::class, $promise);
        $headers = $info->getHeaders();
        self::assertSame(['0123456789abcdef0123456789abcdef'], $headers->get('X-Bella-Key-Id'));
        self::assertSame(['bella-php-sdk'], $headers->get('X-Bella-Client'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $headers->get('X-Bella-Signature')[0]);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $headers->get('X-Bella-Timestamp')[0]);
    }
}
