<?php

namespace Ekyna\Bundle\DpdBundle\Platform\Gateway;

use Ekyna\Bundle\DpdBundle\Platform\DpdPlatform;
use Ekyna\Component\Commerce\Shipment;
use Ekyna\Component\Dpd;

/**
 * Class AbstractRelayGateway
 * @package Ekyna\Bundle\DpdBundle\Platform\Gateway
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
abstract class AbstractRelayGateway extends AbstractGateway
{
    /**
     * @var Dpd\Pudo\Api
     */
    private $pudoApi;


    /**
     * @inheritDoc
     */
    public function getActions()
    {
        return [
            Shipment\Gateway\GatewayActions::SHIP,
            Shipment\Gateway\GatewayActions::CANCEL,
            Shipment\Gateway\GatewayActions::PRINT_LABEL,
            Shipment\Gateway\GatewayActions::LIST_RELAY_POINTS,
            Shipment\Gateway\GatewayActions::GET_RELAY_POINT,
            Shipment\Gateway\GatewayActions::TRACK,
        ];
    }

    /**
     * @inheritDoc
     */
    protected function createSingleShipmentRequest(Shipment\Model\ShipmentInterface $shipment)
    {
        $request = parent::createSingleShipmentRequest($shipment);

        // Receiver relay point
        $relay = $this->addressResolver->resolveReceiverAddress($shipment);
        if (!$relay instanceof Shipment\Model\RelayPointInterface) {
            throw new Dpd\Exception\RuntimeException(
                "Expected instance of " . Shipment\Model\RelayPointInterface::class
            );
        }

        if (null === $request->services) {
            $request->services = new Dpd\EPrint\Model\StdServices();
        }

        $request->services->parcelshop = new Dpd\EPrint\Model\ParcelShop();
        $request->services->parcelshop->shopaddress = new Dpd\EPrint\Model\ShopAddress();
        $request->services->parcelshop->shopaddress->shopid = $relay->getNumber();

        return $request;
    }

    /**
     * @inheritDoc
     */
    protected function doMultiShipment(Shipment\Model\ShipmentInterface $shipment)
    {
        $this->throwUnsupportedAction('parcels shipment');
    }

    /**
     * @inheritDoc
     */
    public function listRelayPoints(Shipment\Gateway\Model\Address $address, float $weight)
    {
        $request = new Dpd\Pudo\Request\GetPudoListRequest();

        $hash = $request->address = $address->getStreet();
        $hash .= $request->zipCode = $address->getPostalCode();
        $hash .= $request->city = $address->getCity();
        $hash .= $request->countrycode = 'FR';
        $hash .= $request->date_from = (new \DateTime('+2 day'))->format('d/m/Y'); // TODO regarding to stock availability

        $request->requestID = substr(md5($hash), 0, 30);

        try {
            $response = $this->getPudoApi()->GetPudoList($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            echo "Error: " . $e->getMessage();
            exit();
        }

        $return = new Shipment\Gateway\Model\ListRelayPointResponse();

        foreach ($response->getItems() as $item) {
            $return->addRelayPoint($this->transformItemToRelayPoint($item));
        }

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function getRelayPoint(string $number)
    {
        $request = new Dpd\Pudo\Request\GetPudoDetailsRequest();

        $request->pudo_id = $number;

        try {
            /** @var \Ekyna\Component\Dpd\Pudo\Response\GetPudoDetailsResponse $response */
            $response = $this->getPudoApi()->GetPudoDetails($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            echo "Error: " . $e->getMessage();
            exit();
        }

        $return = new Shipment\Gateway\Model\GetRelayPointResponse();

        $return->setRelayPoint($this->transformItemToRelayPoint($response->getItem()));

        return $return;
    }

    /**
     * Transforms a dpd relay point item to a commerce one.
     *
     * @param Dpd\Pudo\Model\Item $item
     *
     * @return Shipment\Entity\RelayPoint
     */
    protected function transformItemToRelayPoint(Dpd\Pudo\Model\Item $item)
    {
        $country = $this->addressResolver->getCountryRepository()->findOneByCode('FR');

        $point = new Shipment\Entity\RelayPoint();

        $complement = trim($item->getAddress2());
        $supplement = trim($item->getAddress3());

        $point
            ->setPlatform(DpdPlatform::NAME)
            ->setNumber($item->getId())
            ->setCompany($item->getName())
            ->setStreet(trim($item->getAddress1()))
            ->setComplement(empty($complement) ? null : $complement)
            ->setSupplement(empty($supplement) ? null : $supplement)
            ->setPostalCode($item->getZipCode())
            ->setCity($item->getCity())
            ->setCountry($country)
            ->setDistance($item->getDistance())
            ->setLongitude($item->getLongitude())
            ->setLatitude($item->getLatitude());

        /** @var Shipment\Model\OpeningHour $current */
        $current = null;
        /** @var Dpd\Pudo\Model\OpeningHour $oh */
        foreach ($item->getOpeningHours() as $oh) {
            if ((null === $current) || ($current->getDay() !== $oh->getDay())) {
                $current = new Shipment\Model\OpeningHour();
                $current->setDay($oh->getDay());
                $point->addOpeningHour($current);
            }

            $current->addRanges($oh->getFromTime(), $oh->getToTime());
        }

        return $point;
    }

    /**
     * @inheritDoc
     */
    public function getCapabilities()
    {
        return static::CAPABILITY_SHIPMENT | static::CAPABILITY_RELAY;
    }

    /**
     * @inheritDoc
     */
    public function getMaxWeight()
    {
        return 20;
    }

    /**
     * Returns the pudo api.
     *
     * @return Dpd\Pudo\Api
     */
    protected function getPudoApi()
    {
        if (null !== $this->pudoApi) {
            return $this->pudoApi;
        }

        return $this->pudoApi = new Dpd\Pudo\Api([
            'carrier' => $this->config['pudo']['carrier'],
            'key'     => $this->config['pudo']['key'],
            'cache'   => $this->config['cache'],
            'debug'   => $this->config['debug'],
        ]);
    }
}
