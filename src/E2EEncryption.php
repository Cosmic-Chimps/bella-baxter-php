<?php

declare(strict_types=1);

namespace BellaBaxter;

/**
 * ECDH-P256-HKDF-SHA256-AES256GCM client-side E2EE.
 *
 * Mirrors the algorithm used by the JS, Python, Go, Java, and .NET SDKs.
 * Requires: ext-openssl (bundled with PHP 8.1+).
 *
 * Usage:
 *   $e2ee = new E2EEncryption();
 *   // Send $e2ee->publicKeyBase64 as the X-E2E-Public-Key request header.
 *   // On response: $secrets = $e2ee->decrypt($responseBodyJson); // throws E2EEResponseException
 *   //   when the body is not an envelope or does not decrypt (#1050) — never returns plaintext.
 */
final class E2EEncryption
{
    private const HKDF_INFO = 'bella-e2ee-v1';
    private const CURVE     = 'prime256v1'; // P-256 / secp256r1

    /** Base64-encoded SPKI public key — send as X-E2E-Public-Key header. */
    public readonly string $publicKeyBase64;

    private readonly \OpenSSLAsymmetricKey $privateKey;

    /**
     * The device key a client should use: `$explicit`, else `BELLA_BAXTER_PRIVATE_KEY`.
     *
     * A blank value (empty or whitespace, from either source) means "no device key", as in every
     * other SDK. Anything else is returned as-is and must then load as a P-256 key (`fromPem`), or
     * construction fails loudly.
     */
    public static function resolveDeviceKey(?string $explicit): ?string
    {
        $clean = static fn ($v): ?string => (is_string($v) && trim($v) !== '') ? $v : null;
        return $clean($explicit) ?? $clean(getenv('BELLA_BAXTER_PRIVATE_KEY'));
    }

    /**
     * Create an E2EEncryption instance from a PKCS#8 PEM private key (ZKE persistent device key).
     *
     * Accepts both PKCS#8 (`-----BEGIN PRIVATE KEY-----`) and SEC1 (`-----BEGIN EC PRIVATE KEY-----`)
     * formats — openssl_pkey_get_private() handles both transparently.
     *
     * @param  string $pem PEM-encoded EC private key (from `bella auth setup`).
     * @throws \RuntimeException if the key cannot be parsed or is not a P-256 EC key.
     */
    public static function fromPem(string $pem): self
    {
        $privateKey = openssl_pkey_get_private($pem);
        if ($privateKey === false) {
            throw new \RuntimeException('ZKE: could not parse PEM private key: ' . openssl_error_string());
        }

        $details = openssl_pkey_get_details($privateKey);
        if ($details === false || ($details['type'] ?? -1) !== OPENSSL_KEYTYPE_EC) {
            throw new \RuntimeException('ZKE: private key must be an EC (P-256) key');
        }
        // The platform's ECIES is P-256 only. Any other curve used to load here and then fail on the
        // server with an unclear error; refuse it where the cause is still visible.
        $curve = $details['ec']['curve_name'] ?? null;
        if ($curve !== 'prime256v1') {
            throw new \RuntimeException('ZKE: private key must be a P-256 (prime256v1) key, not ' . ($curve ?? 'an unnamed curve'));
        }

        // Bypass the constructor to avoid generating a throw-away ephemeral key.
        // Readonly properties are uninitialized at this point and can be assigned
        // exactly once from within this class scope.
        /** @var self $instance */
        $instance = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $instance->privateKey      = $privateKey;
        $instance->publicKeyBase64 = self::pemToDerBase64($details['key']); // SPKI DER base64

        return $instance;
    }

    public function __construct()
    {
        $key = openssl_pkey_new([
            'curve_name'       => self::CURVE,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new \RuntimeException('E2EEncryption: failed to generate EC key pair: ' . openssl_error_string());
        }

        $this->privateKey = $key;

        // Export as DER-encoded SubjectPublicKeyInfo (SPKI)
        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['key'])) {
            throw new \RuntimeException('E2EEncryption: failed to export public key');
        }

        // openssl_pkey_get_details returns PEM; convert to DER for SPKI
        $pem = $details['key'];
        $this->publicKeyBase64 = self::pemToDerBase64($pem);
    }

    /**
     * Decrypt an encrypted secrets payload from the Bella Baxter API.
     *
     * #1050 — the body MUST be an envelope. A plain answer is refused, not returned: this helper is only
     * meaningful after the caller presented {@see $publicKeyBase64}, and a plaintext answer to a presented
     * key is exactly what a header-stripping intermediary or a server regression would serve.
     *
     * @param  string      $responseBody Raw JSON string from the API response.
     * @param  string|null $path         Request path, named in the error message only.
     * @return array<string,string> Decrypted secrets map.
     * @throws E2EEResponseException e2ee-plaintext-response (not an envelope) or e2ee-decryption-failed.
     */
    public function decrypt(string $responseBody, ?string $path = null): array
    {
        $plaintext = $this->decryptRaw($responseBody, $path);
        try {
            $parsed = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw E2EEResponseException::decryptionFailed($path, $e);
        }
        if (!is_array($parsed)) {
            throw E2EEResponseException::decryptionFailed($path);
        }
        return self::secretsFromPlaintext($parsed);
    }

    /**
     * Decrypt the raw plaintext JSON string of an encrypted payload.
     *
     * Unlike {@see decrypt()} this returns the full decrypted JSON string without
     * any transformation, so the caller can handle the response shape itself
     * (preserving version, environmentSlug, lastModified, etc.).
     *
     * #1050 — a body that is not an envelope is REFUSED (it used to be returned as-is), and so is an
     * envelope that does not decrypt: malformed, tampered (the GCM tag fails) or encrypted to another key.
     *
     * @param  string      $responseBody Raw JSON string of the encrypted API response.
     * @param  string|null $path         Request path, named in the error message only.
     * @return string Decrypted plaintext JSON string.
     * @throws E2EEResponseException e2ee-plaintext-response (not an envelope) or e2ee-decryption-failed.
     */
    public function decryptRaw(string $responseBody, ?string $path = null): string
    {
        $payload = self::envelopeOf($responseBody);
        if ($payload === null) {
            throw E2EEResponseException::plaintext($path);
        }

        try {
            return $this->open($payload);
        } catch (\Throwable $e) {
            throw E2EEResponseException::decryptionFailed($path, $e);
        }
    }

    /**
     * The body as an envelope (`"encrypted": true`, a JSON object), or null when it is anything else:
     * not JSON, not an object, or an object without `"encrypted": true` — i.e. plain secrets.
     *
     * @return array<string,mixed>|null
     */
    public static function envelopeOf(string $responseBody): ?array
    {
        try {
            $payload = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload) || array_is_list($payload) || ($payload['encrypted'] ?? null) !== true) {
            return null;
        }
        return $payload;
    }

    /**
     * The secrets map inside a decrypted plaintext. Three possible server response shapes:
     *   1. Full AllEnvironmentSecretsResponse: {"environmentSlug":..., "secrets":{...}, ...}
     *   2. Array of SecretItem:                [{key:"K", value:"V"}, ...]
     *   3. Legacy flat dict:                   {"K": "V", ...}
     *
     * @param  array<mixed> $parsed
     * @return array<string,string>
     */
    public static function secretsFromPlaintext(array $parsed): array
    {
        if (isset($parsed['secrets']) && is_array($parsed['secrets']) && !array_is_list($parsed['secrets'])) {
            // Full response — extract nested secrets dict.
            return array_map('strval', $parsed['secrets']);
        }

        if (array_is_list($parsed)) {
            $result = [];
            foreach ($parsed as $item) {
                if (is_array($item) && isset($item['key'])) {
                    $result[$item['key']] = (string) ($item['value'] ?? '');
                }
            }
            return $result;
        }

        // Legacy flat associative array.
        return $parsed;
    }

    /**
     * ECDH (P-256) → HKDF-SHA256 → AES-256-GCM. Throws on any failure; never returns the input.
     *
     * @param array<string,mixed> $payload An envelope from {@see envelopeOf()}.
     */
    private function open(array $payload): string
    {
        $field = static function (string $name) use ($payload): string {
            $value = $payload[$name] ?? null;
            $bytes = is_string($value) ? base64_decode($value, true) : false;
            if ($bytes === false || $bytes === '') {
                throw new \RuntimeException("E2EEncryption: envelope field '{$name}' is missing or not base64");
            }
            return $bytes;
        };
        $serverPubBytes = $field('serverPublicKey');
        $nonce          = $field('nonce');
        $tag            = $field('tag');
        $ciphertext     = $field('ciphertext');

        // 1. Import server ephemeral public key (SPKI DER → PEM)
        $serverPubKey = openssl_pkey_get_public(self::derToPem($serverPubBytes, 'PUBLIC KEY'));
        if ($serverPubKey === false) {
            throw new \RuntimeException('E2EEncryption: failed to import server public key: ' . openssl_error_string());
        }

        // 2. ECDH → raw shared secret (openssl_pkey_derive is the ECDH function, PHP 8.1+)
        $sharedSecret = openssl_pkey_derive($serverPubKey, $this->privateKey);
        if ($sharedSecret === false) {
            throw new \RuntimeException('E2EEncryption: ECDH failed: ' . openssl_error_string());
        }

        // 3. HKDF-SHA256 → 32-byte AES key (salt = 32 zero bytes per RFC 5869 §2.2)
        $aesKey = self::hkdfSha256($sharedSecret, str_repeat("\x00", 32), self::HKDF_INFO, 32);

        // 4. AES-256-GCM decrypt — false when the tag fails (tampered, or encrypted to another key)
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $aesKey, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('E2EEncryption: AES-GCM decryption failed: ' . openssl_error_string());
        }

        return $plaintext;
    }

    // ── HKDF-SHA256 (RFC 5869) ────────────────────────────────────────────────

    private static function hkdfSha256(string $ikm, string $salt, string $info, int $length): string
    {
        // PHP 7.1.2+ has hash_hkdf()
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $ikm, $length, $info, $salt);
        }

        // Manual HKDF fallback for older builds
        // Extract
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        // Expand: T(1) = HMAC-SHA256(PRK, info || 0x01) — 32 bytes = 1 block
        return hash_hmac('sha256', $info . "\x01", $prk, true);
    }

    // ── PEM ↔ DER helpers ─────────────────────────────────────────────────────

    /** Convert PEM public key to base64-encoded DER (SPKI). */
    private static function pemToDerBase64(string $pem): string
    {
        $lines = explode("\n", trim($pem));
        $der   = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '-----')) {
                continue;
            }
            $der .= $line;
        }
        return $der; // already base64 without newlines
    }

    /** Wrap raw DER bytes in PEM armor. */
    private static function derToPem(string $der, string $type): string
    {
        $b64  = chunk_split(base64_encode($der), 64, "\n");
        return "-----BEGIN {$type}-----\n{$b64}-----END {$type}-----\n";
    }
}
