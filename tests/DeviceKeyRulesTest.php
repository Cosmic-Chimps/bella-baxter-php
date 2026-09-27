<?php

declare(strict_types=1);

namespace BellaBaxter\Tests;

use BellaBaxter\E2EEncryption;
use PHPUnit\Framework\TestCase;

// Loaded directly so this test needs neither the generated client nor `composer install`.
require_once __DIR__ . '/../src/E2EEncryption.php';

/**
 * The device key rules every SDK shares (the same hold in JS, Java, .NET, Swift, Dart, Go, Python, Ruby):
 *
 *  - the key must be P-256. The platform's ECIES is P-256 only; another curve used to load here and
 *    then fail on the server with an unclear error;
 *  - a BLANK BELLA_BAXTER_PRIVATE_KEY (empty or whitespace), or a blank explicit key, means "no device
 *    key", never an error.
 *
 * Keys are generated per test, so no key material is committed.
 */
final class DeviceKeyRulesTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv('BELLA_BAXTER_PRIVATE_KEY');
    }

    protected function tearDown(): void
    {
        $this->saved === false
            ? putenv('BELLA_BAXTER_PRIVATE_KEY')
            : putenv('BELLA_BAXTER_PRIVATE_KEY=' . $this->saved);
    }

    private static function ecPem(string $curve): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve]);
        openssl_pkey_export($key, $pem);
        return $pem;
    }

    public function testAP256KeyLoads(): void
    {
        $e2ee = E2EEncryption::fromPem(self::ecPem('prime256v1'));
        self::assertNotSame('', $e2ee->publicKeyBase64);
    }

    public function testAP384KeyIsRefusedNamingTheCurve(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/P-256.*secp384r1/');
        E2EEncryption::fromPem(self::ecPem('secp384r1'));
    }

    public function testABlankVariableMeansNoDeviceKey(): void
    {
        foreach (['', '   ', "\n\t "] as $blank) {
            putenv('BELLA_BAXTER_PRIVATE_KEY=' . $blank);
            self::assertNull(E2EEncryption::resolveDeviceKey(null), 'variable ' . json_encode($blank));
            self::assertNull(E2EEncryption::resolveDeviceKey($blank), 'explicit ' . json_encode($blank));
        }
    }

    public function testAnExplicitKeyWinsOverTheVariable(): void
    {
        putenv('BELLA_BAXTER_PRIVATE_KEY=from-env');
        self::assertSame('explicit', E2EEncryption::resolveDeviceKey('explicit'));
        self::assertSame('from-env', E2EEncryption::resolveDeviceKey('  '));
        self::assertSame('from-env', E2EEncryption::resolveDeviceKey(null));
    }

    public function testNoKeyAnywhereMeansNull(): void
    {
        putenv('BELLA_BAXTER_PRIVATE_KEY');
        self::assertNull(E2EEncryption::resolveDeviceKey(null));
    }
}
