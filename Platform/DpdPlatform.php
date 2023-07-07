<?php

declare(strict_types=1);

namespace Ekyna\Bundle\DpdBundle\Platform;

use Ekyna\Bundle\SettingBundle\Manager\SettingManagerInterface;
use Ekyna\Component\Commerce\Exception\InvalidArgumentException;
use Ekyna\Component\Commerce\Shipment\Gateway\AbstractPlatform;
use Ekyna\Component\Commerce\Shipment\Gateway\GatewayInterface;
use Ekyna\Component\Commerce\Shipment\Gateway\PlatformActions;
use Ekyna\Component\Dpd\EPrint\Enum\ELabelType;
use Symfony\Component\Config\Definition;

/**
 * Class DpdPlatform
 * @package Ekyna\Bundle\DpdBundle\Platform
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
class DpdPlatform extends AbstractPlatform
{
    public const NAME = 'DPD';

    protected SettingManagerInterface $settingManager;
    protected array $config;


    public function __construct(SettingManagerInterface $settingManager, array $config = [])
    {
        $this->settingManager = $settingManager;
        $this->config = $config;
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getActions(): array
    {
        return [
            PlatformActions::PRINT_LABELS,
        ];
    }

    public function createGateway(string $name, array $config = []): GatewayInterface
    {
        $config = array_replace([
            'label_type' => ELabelType::PNG,
        ], $config);

        $class = sprintf('Ekyna\Bundle\DpdBundle\Platform\Gateway\%sGateway', $config['service']);
        if (!class_exists($class)) {
            throw new InvalidArgumentException(sprintf("Unexpected service '%s'", $config['service']));
        }

        /** @var Gateway\AbstractGateway $gateway */
        $gateway = new $class($this, $name, array_replace($this->config, $this->processGatewayConfig($config)));

        $gateway->setSettingManager($this->settingManager);

        return $gateway;
    }

    protected function createConfigDefinition(Definition\Builder\NodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->scalarNode('customer_number')
                    ->info('Numéro client')
                    ->isRequired()
                ->end()
                ->scalarNode('center_number')
                    ->info('Code dépôt')
                    ->isRequired()
                ->end()
                ->scalarNode('country_code')
                    ->info('Code pays')
                    ->isRequired()
                ->end()
                ->enumNode('service')
                    ->info('Service')
                    ->values(Service::getChoices())
                    ->isRequired()
                ->end()
                ->enumNode('label_type')
                    ->info('Label Type')
                    ->defaultValue(ELabelType::PDF)
                    ->values([
                        'PNG'                 => ELabelType::PNG,
                        ELabelType::PDF       => ELabelType::PDF,
                        ELabelType::PDF_A6    => ELabelType::PDF_A6,
                        ELabelType::EPL       => ELabelType::EPL,
                        ELabelType::ZPL       => ELabelType::ZPL,
                        ELabelType::ZPL_A6    => ELabelType::ZPL_A6,
                        ELabelType::ZPL300    => ELabelType::ZPL300,
                        ELabelType::ZPL300_A6 => ELabelType::ZPL300_A6,
                    ])
                    ->isRequired()
                ->end()
            ->end();
    }
}
