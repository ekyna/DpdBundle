<?php

namespace Ekyna\Bundle\DpdBundle\DependencyInjection;

use Ekyna\Bundle\DpdBundle\Platform\DpdPlatform;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * Class EkynaDpdExtension
 * @package Ekyna\Bundle\DpdBundle
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
class EkynaDpdExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $platformDef = new Definition(DpdPlatform::class);
        $platformDef->addTag('ekyna_commerce.shipment.gateway_platform');
        $platformDef->addArgument(new Reference('ekyna_setting.manager'));
        $platformDef->addArgument(new Reference('ekyna_commerce.constants_helper'));
        $platformDef->addArgument($config);
        $container->setDefinition('ekyna_dpd.gateway_platform', $platformDef);
    }
}
