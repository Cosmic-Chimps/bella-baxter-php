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
 *   // On response: $secrets = $e2ee->decrypt($responseBodyJson);
 */
final class E2EEncryption
{
    private const HKDF_INFO = 'bella-e2ee-v1';
    private const CURVE     = 'prime256v1'; // P-256 / secp256r1

    /** Base64-encoded SPKI public key — send as X-E2E-Public-Key header. */
    public readonly string $publicKeyBase64;

    private readonly \OpenSSLAsymmetricKey $privateKey;

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
     * @param  string $responseBody Raw JSON string from the API response.
     * @return array<string,string> Decrypted secrets map.
     * @throws \RuntimeException on decryption failure.
     */
    public function decrypt(string $responseBody): array
    {
        $payload = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

        if (empty($payload['encrypted'])) {
            // Plain response — return as-is (filter to string values only)
            return array_filter(
                $payload,
                static fn($v) => is_string($v)
            );
        }

        $serverPubBytes = base64_decode($payload['serverPublicKey'], true);
        $nonce          = base64_decode($payload['nonce'],           true);
        $tag            = base64_decode($payload['tag'],             true);
        $ciphertext     = base64_decode($payload['ciphertext'],      true);

        if ($serverPubBytes === false || $nonce === false || $tag === false || $ciphertext === false) {
            throw new \RuntimeException('E2EEncryption: failed to base64-decode payload fields');
        }

        // 1. Import server ephemeral public key (SPKI DER → PEM)
        $serverPubPem = self::derToPem($serverPubBytes, 'PUBLIC KEY');
        $serverPubKey = openssl_pkey_get_public($serverPubPem);
        if ($serverPubKey === false) {
            throw new \RuntimeException('E2EEncryption: failed to import server public key: ' . openssl_error_string());
        }

        // 2. ECDH → raw shared secret
        // openssl_pkey_derive() is the correct ECDH function (PHP 8.1+)
        $sharedSecret = openssl_pkey_derive($serverPubKey, $this->privateKey);
        if ($sharedSecret === false) {
            throw new \RuntimeException('E2EEncryption: ECDH failed: ' . openssl_error_string());
        }

        // 3. HKDF-SHA256 → 32-byte AES key (salt = 32 zero bytes per RFC 5869 §2.2)
        $salt   = str_repeat("\x00", 32);
        $aesKey = self::hkdfSha256($sharedSecret, $salt, self::HKDF_INFO, 32);

        // 4. AES-256-GCM decrypt
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $aesKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('E2EEncryption: AES-GCM decryption failed: ' . openssl_error_string());
        }

        $parsed = json_decode($plaintext, true, 512, JSON_THROW_ON_ERROR);

        // Three possible server response shapes:
        //   1. Full AllEnvironmentSecretsResponse: {"environmentSlug":..., "secrets":{...}, ...}
        //   2. Array of SecretItem:                [{key:"K", value:"V"}, ...]
        //   3. Legacy flat dict:                   {"K": "V", ...}
        if (isset($parsed['secrets']) && is_array($parsed['secrets']) && !array_is_list($parsed['secrets'])) {
            // Full response — extract nested secrets dict.
            return array_map('strval', $parsed['secrets']);
        }

        if (array_is_list($parsed)) {
            $result = [];
            foreach ($parsed as $item) {
                if (isset($item['key'])) {
                    $result[$item['key']] = (string) ($item['value'] ?? '');
                }
            }
            return $result;
        }

        // Legacy flat associative array.
        return $parsed;
    }

    /**
     * Decrypt the raw plaintext JSON string of an encrypted payload.
     *
     * Unlike {@see decrypt()} this returns the full decrypted JSON string without
     * any transformation, so the caller can handle the response shape itself
     * (preserving version, environmentSlug, lastModified, etc.).
     *
     * @param  string $responseBody Raw JSON string of the encrypted API response.
     * @return string Decrypted plaintext JSON string.
     * @throws \RuntimeException on decryption failure or unencrypted input.
     */
    public function decryptRaw(string $responseBody): string
    {
        $payload = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);

        if (empty($payload['encrypted'])) {
            return $responseBody;
        }

        $serverPubBytes = base64_decode($payload['serverPublicKey'], true);
        $nonce          = base64_decode($payload['nonce'],           true);
        $tag            = base64_decode($payload['tag'],             true);
        $ciphertext     = base64_decode($payload['ciphertext'],      true);

        if ($serverPubBytes === false || $nonce === false || $tag === false || $ciphertext === false) {
            throw new \RuntimeException('E2EEncryption: failed to base64-decode payload fields');
        }

        $serverPubPem = self::derToPem($serverPubBytes, 'PUBLIC KEY');
        $serverPubKey = openssl_pkey_get_public($serverPubPem);
        if ($serverPubKey === false) {
            throw new \RuntimeException('E2EEncryption: failed to import server public key: ' . openssl_error_string());
        }

        $sharedSecret = openssl_pkey_derive($serverPubKey, $this->privateKey);
        if ($sharedSecret === false) {
            throw new \RuntimeException('E2EEncryption: ECDH failed: ' . openssl_error_string());
        }
        $salt      = str_repeat("\x00", 32);
        $aesKey    = self::hkdfSha256($sharedSecret, $salt, self::HKDF_INFO, 32);
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
