<?php

declare(strict_types=1);

namespace BellaBaxter\Symfony;

use BellaBaxter\BaxterClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * BellaSecretsLoader — loads secrets from Bella Baxter into $_ENV / putenv().
 *
 * Runs once on the first master request (not on sub-requests).
 * After this, all secrets are available via $_ENV['KEY'] or getenv('KEY').
 *
 * In PHP-FPM: this runs once per worker process (at first request),
 * then the secrets are cached in memory for the worker's lifetime.
 */
class BellaSecretsLoader implements EventSubscriberInterface
{
    private bool $loaded = false;

    public function __construct(
        private readonly BaxterClient $client,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        // HIGH priority so secrets are available before any controller runs
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 256]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($this->loaded || !$event->isMainRequest()) {
            return;
        }

        $this->loaded = true;

        try {
            $secrets = $this->client->getAllSecrets();

            foreach ($secrets as $key => $value) {
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
                putenv("{$key}={$value}");
            }

            $this->logger?->info('Bella: loaded ' . count($secrets) . ' secret(s)');
        } catch (\Throwable $e) {
            $this->logger?->warning('Bella: failed to load secrets', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
