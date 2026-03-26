<?php

declare(strict_types=1);

namespace BellaBaxter\Symfony;

use BellaBaxter\BaxterClient;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use BellaBaxter\BaxterClientOptions;

/**
 * BellaBundle — integrates Bella Baxter secrets into Symfony.
 *
 * Register in config/bundles.php:
 *   BellaBaxter\Symfony\BellaBundle::class => ['all' => true],
 *
 * Configuration in config/packages/bella.yaml:
 *   bella:
 *     url: '%env(BELLA_BAXTER_URL)%'
 *     api_key: '%env(BELLA_BAXTER_API_KEY)%'
 *     auto_load: true
 */
class BellaBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set(BaxterClient::class)
            ->args([
                inline_service(BaxterClientOptions::class)->args([
                    $config['url'],
                    $config['api_key'],
                ]),
            ])
            ->public();

        if ($config['auto_load'] ?? true) {
            $container->services()
                ->set(BellaSecretsLoader::class)
                ->args([service(BaxterClient::class)])
                ->tag('kernel.event_subscriber');
        }
    }
}
