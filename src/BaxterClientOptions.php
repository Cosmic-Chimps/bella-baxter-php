<?php

declare(strict_types=1);

namespace BellaBaxter;

/**
 * Configuration options for the Bella Baxter PHP SDK.
 */
final class BaxterClientOptions
{
    /**
     * @param string $baxterUrl      Base URL of the Baxter API (e.g. https://api.bella-baxter.io).
     * @param string $apiKey         Bella Baxter API key (bax-...). Obtain via WebApp or: bella apikeys create.
     * @param int    $timeoutSeconds HTTP request timeout in seconds (default: 10).
     */
    public function __construct(
        public readonly string $baxterUrl = 'https://api.bella-baxter.io',
        public readonly string $apiKey,
        public readonly int    $timeoutSeconds = 10,
    ) {}
}
