<?php

declare(strict_types=1);

namespace BellaBaxter;

/**
 * Verifies the X-Bella-Signature header on incoming Bella Baxter webhook requests.
 *
 * Header format: X-Bella-Signature: t={unix_epoch_seconds},v1={hmac_sha256_hex}
 * Signing input:  {t}.{rawBodyJson}  (UTF-8)
 * HMAC key:       the raw whsec-xxx signing secret (UTF-8 encoded)
 */
final class WebhookSignatureVerifier
{
    private const DEFAULT_TOLERANCE = 300;

    /**
     * Verifies a webhook request signature.
     *
     * @param string $secret          The whsec-xxx signing secret
     * @param string $signatureHeader Value of the X-Bella-Signature header
     * @param string $rawBody         Raw request body string (UTF-8)
     * @param int    $tolerance       Max age of timestamp in seconds (default 300)
     *
     * @return bool true if the signature is valid and within the timestamp tolerance
     */
    public static function verify(
        string $secret,
        string $signatureHeader,
        string $rawBody,
        int $tolerance = self::DEFAULT_TOLERANCE
    ): bool {
        $t  = null;
        $v1 = null;

        foreach (explode(',', $signatureHeader) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $t = substr($part, 2);
            } elseif (str_starts_with($part, 'v1=')) {
                $v1 = substr($part, 3);
            }
        }

        if ($t === null || $v1 === null || $t === '' || $v1 === '') {
            return false;
        }

        if (!ctype_digit($t)) {
            return false;
        }

        $timestamp = (int) $t;

        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$t}.{$rawBody}", $secret);

        return hash_equals($expected, $v1);
    }
}
