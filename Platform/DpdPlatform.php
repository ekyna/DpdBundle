<?php

namespace Ekyna\Bundle\DpdBundle\Platform;

use Ekyna\Bundle\CommerceBundle\Service\ConstantsHelper;
use Ekyna\Bundle\SettingBundle\Manager\SettingsManagerInterface;
use Ekyna\Component\Commerce\Exception\InvalidArgumentException;
use Ekyna\Component\Commerce\Shipment\Gateway\AbstractPlatform;
use Ekyna\Component\Commerce\Shipment\Gateway\PlatformActions;
use Symfony\Component\Config\Definition;

/**
 * Class DpdPlatform
 * @package Ekyna\Bundle\DpdBundle\Platform
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
class DpdPlatform extends AbstractPlatform
{
    const NAME = 'DPD';

    /**
     * @var SettingsManagerInterface
     */
    protected $settingManager;

    /**
     * @var ConstantsHelper
     */
    protected $constantsHelper;

    /**
     * @var array
     */
    protected $config;


    /**
     * Constructor.
     *
     * @param SettingsManagerInterface $settingManager
     * @param ConstantsHelper          $constantsHelper
     * @param array                    $config
     */
    public function __construct(
        SettingsManagerInterface $settingManager,
        ConstantsHelper $constantsHelper,
        array $config = []
    ) {
        $this->settingManager = $settingManager;
        $this->constantsHelper = $constantsHelper;
        $this->config = $config;
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return static::NAME;
    }

    /**
     * @inheritDoc
     */
    public function getActions()
    {
        return [
            PlatformActions::PRINT_LABELS,
        ];
    }

    /**
     * @inheritDoc
     */
    public function createGateway($name, array $config = [])
    {
        $class = sprintf('Ekyna\Bundle\DpdBundle\Platform\Gateway\%sGateway', $config['service']);
        if (!class_exists($class)) {
            throw new InvalidArgumentException(sprintf("Unexpected service '%s'", $config['service']));
        }

        /** @var Gateway\AbstractGateway $gateway */
        $gateway = new $class($this, $name, array_replace($this->config, $this->processGatewayConfig($config)));

        $gateway->setSettingManager($this->settingManager);
        $gateway->setConstantsHelper($this->constantsHelper);

        return $gateway;
    }

    /**
     * @inheritDoc
     */
    protected function createConfigDefinition(Definition\Builder\NodeDefinition $rootNode)
    {
        /** @noinspection PhpUndefinedMethodInspection */
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
            ->end();
    }
}
