<?php

namespace Ekyna\Bundle\DpdBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Class Configuration
 * @package Ekyna\Bundle\DpdBundle
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('ekyna_dpd');

        $rootNode
            ->children()
                ->arrayNode('eprint')
                    ->children()
                        ->scalarNode('login')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('password')
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('pudo')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('carrier')
                            ->cannotBeEmpty()
                            ->defaultValue('EXA')
                        ->end()
                        ->scalarNode('key')
                            ->cannotBeEmpty()
                            ->defaultValue('deecd7bc81b71fcc0e292b53e826c48f')
                        ->end()
                    ->end()
                ->end()
                ->booleanNode('cache')
                    ->defaultTrue()
                ->end()
                ->booleanNode('debug')
                    ->defaultValue('%kernel.debug%')
                ->end()
                ->booleanNode('test')
                    ->defaultFalse()
                ->end()
                ->booleanNode('ssl_check')
                    ->defaultTrue()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
