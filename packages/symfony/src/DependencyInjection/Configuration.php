<?php

declare(strict_types=1);

namespace BellaBaxter\Symfony\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('bella');
        $rootNode    = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('url')
                    ->defaultValue('https://api.bella-baxter.io')
                    ->info('Base URL of the Baxter API')
                ->end()
                ->scalarNode('api_key')
                    ->defaultValue('')
                    ->info('API key (from bella apikeys create)')
                ->end()
                ->booleanNode('auto_load')
                    ->defaultTrue()
                    ->info('Automatically load secrets into $_ENV on the first request')
                ->end()
            ->end();

        return $treeBuilder;
    }
}
