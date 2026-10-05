<?php

declare(strict_types=1);

namespace BellaBaxter;

/**
 * A secrets response was refused because it was not the E2EE envelope the SDK asked for (#1050).
 *
 * Once this SDK has presented its `X-E2E-Public-Key` on a read that carries secret values, a `2xx`
 * answer that is not a decryptable envelope is an error, never a value (apps/sdk/SDK_CONTRACT.md,
 * "Rule: a presented key requires an envelope"). There is no plaintext fallback.
 *
 * The code is the stable, cross-SDK contract; read it with {@see getErrorCode()} (PHP's own
 * `getCode()` is an int and stays 0). The message names the request path and the code, never the
 * body, ciphertext or key material. A decryption failure keeps its cause as the previous exception.
 */
final class E2EEResponseException extends \RuntimeException
{
    /** The key was presented, and the answer was not an envelope (plain secrets, or not JSON at all). */
    public const PLAINTEXT_RESPONSE = 'e2ee-plaintext-response';

    /** The answer claimed to be an envelope and did not decrypt: malformed, tampered, or for another key. */
    public const DECRYPTION_FAILED = 'e2ee-decryption-failed';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function plaintext(?string $path = null): self
    {
        return new self(
            self::PLAINTEXT_RESPONSE,
            sprintf('E2EE response expected but plaintext received for %s; refusing it (%s)', $path ?? 'the secrets response', self::PLAINTEXT_RESPONSE),
        );
    }

    public static function decryptionFailed(?string $path = null, ?\Throwable $previous = null): self
    {
        return new self(
            self::DECRYPTION_FAILED,
            sprintf('E2EE response could not be decrypted for %s; refusing it (%s)', $path ?? 'the secrets response', self::DECRYPTION_FAILED),
            $previous,
        );
    }
}
