<?php

declare(strict_types=1);

namespace Ekyna\Bundle\DpdBundle\Platform\Gateway;

use DateTime;
use Decimal\Decimal;
use Ekyna\Bundle\DpdBundle\Platform\DpdPlatform;
use Ekyna\Component\Commerce\Common\Model\Address;
use Ekyna\Component\Commerce\Exception\ShipmentGatewayException;
use Ekyna\Component\Commerce\Shipment;
use Ekyna\Component\Dpd;

/**
 * Class AbstractRelayGateway
 * @package Ekyna\Bundle\DpdBundle\Platform\Gateway
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
abstract class AbstractRelayGateway extends AbstractGateway
{
    private ?Dpd\Pudo\Api $pudoApi = null;

    public function getActions(): array
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

    protected function createSingleShipmentRequest(
        Shipment\Model\ShipmentInterface $shipment
    ): Dpd\EPrint\Request\StdShipmentLabelRequest {
        $request = parent::createSingleShipmentRequest($shipment);

        // Receiver relay point
        $relay = $this->addressResolver->resolveReceiverAddress($shipment);
        if (!$relay instanceof Shipment\Model\RelayPointInterface) {
            throw new Dpd\Exception\RuntimeException(
                'Expected instance of ' . Shipment\Model\RelayPointInterface::class
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

    protected function doMultiShipment(Shipment\Model\ShipmentInterface $shipment): bool
    {
        $this->throwUnsupportedAction('parcels shipment');
    }

    public function listRelayPoints(
        Address $address,
        Decimal $weight
    ): Shipment\Gateway\Model\ListRelayPointResponse {
        $request = new Dpd\Pudo\Request\GetPudoListRequest();

        $hash = $request->address = $address->getStreet();
        $hash .= $request->zipCode = $address->getPostalCode();
        $hash .= $request->city = $address->getCity();
        $hash .= $request->countrycode = 'FR';
        $hash .= $request->date_from = (new DateTime('+2 day'))->format('d/m/Y'); // TODO regarding to stock availability

        $request->requestID = substr(md5($hash), 0, 30);

        try {
            $response = $this->getPudoApi()->GetPudoList($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $return = new Shipment\Gateway\Model\ListRelayPointResponse();

        foreach ($response->getItems() as $item) {
            $return->addRelayPoint($this->transformItemToRelayPoint($item));
        }

        return $return;
    }

    public function getRelayPoint(string $number): Shipment\Gateway\Model\GetRelayPointResponse
    {
        $request = new Dpd\Pudo\Request\GetPudoDetailsRequest();

        $request->pudo_id = $number;

        try {
            $response = $this->getPudoApi()->GetPudoDetails($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $return = new Shipment\Gateway\Model\GetRelayPointResponse();

        $return->setRelayPoint($this->transformItemToRelayPoint($response->getItem()));

        return $return;
    }

    /**
     * Transforms a dpd relay point item to a commerce one.
     */
    protected function transformItemToRelayPoint(Dpd\Pudo\Model\Item $item): Shipment\Entity\RelayPoint
    {
        $country = $this->addressResolver->getCountryRepository()->findOneByCode('FR');

        $point = new Shipment\Entity\RelayPoint();

        $complement = trim($item->getAddress2());
        $supplement = trim($item->getAddress3());

        $point
            ->setPlatformName(DpdPlatform::NAME)
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

        $current = null;
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

    public function getCapabilities(): int
    {
        return static::CAPABILITY_SHIPMENT | static::CAPABILITY_RELAY;
    }

    public function getMaxWeight(): ?Decimal
    {
        return new Decimal(20);
    }

    /**
     * Returns the PUDO API.
     */
    protected function getPudoApi(): Dpd\Pudo\Api
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
