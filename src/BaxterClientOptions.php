<?php

declare(strict_types=1);

namespace BellaBaxter;

/**
 * Configuration options for the Bella Baxter PHP SDK.
 */
final class BaxterClientOptions
{
    /**
     * @param string          $baxterUrl             Base URL of the Baxter API (e.g. https://api.bella-baxter.io).
     * @param string          $apiKey                Bella Baxter API key (bax-...). Obtain via WebApp or: bella apikeys create.
     * @param int             $timeoutSeconds        HTTP request timeout in seconds (default: 10).
     * @param string|null     $privateKey            PKCS#8 PEM private key for ZKE transport (from `bella auth setup`).
     *                                               Falls back to the BELLA_BAXTER_PRIVATE_KEY environment variable.
     * @param callable|null   $onWrappedDekReceived  Called when the server returns an X-Bella-Wrapped-Dek header.
     *                                               Signature: (string $wrappedDek, ?string $leaseExpires): void
     */
    public function __construct(
        public readonly string $baxterUrl            = 'https://api.bella-baxter.io',
        public readonly string $apiKey,
        public readonly int    $timeoutSeconds        = 10,
        public readonly ?string $privateKey           = null,
        /** @var callable|null */
        public readonly mixed  $onWrappedDekReceived  = null,
    ) {}
}
