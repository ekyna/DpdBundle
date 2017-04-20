<?php

declare(strict_types=1);

namespace Ekyna\Bundle\DpdBundle\DependencyInjection;

use Ekyna\Bundle\DpdBundle\Platform\DpdPlatform;
use Ekyna\Component\Commerce\Bridge\Symfony\DependencyInjection\ShipmentGatewayRegistryPass;
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
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $platformDef = new Definition(DpdPlatform::class);
        $platformDef->addTag(ShipmentGatewayRegistryPass::PLATFORM_TAG);
        $platformDef->addArgument(new Reference('ekyna_setting.manager'));
        $platformDef->addArgument($config);
        $container->setDefinition('ekyna_dpd.gateway_platform', $platformDef);
    }
}
