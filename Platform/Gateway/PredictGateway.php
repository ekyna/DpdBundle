<?php

namespace Ekyna\Bundle\DpdBundle\Platform\Gateway;

use Ekyna\Component\Commerce\Shipment\Model as Shipment;
use Ekyna\Component\Dpd\EPrint;
use Ekyna\Component\Commerce\Shipment\Model\ShipmentInterface;

/**
 * Class PredictGateway
 * @package Ekyna\Bundle\DpdBundle
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
class PredictGateway extends AbstractGateway
{
    /**
     * @inheritDoc
     */
    public function getCapabilities()
    {
        return static::CAPABILITY_SHIPMENT | static::CAPABILITY_PARCEL;
    }

    /**
     * @inheritDoc
     */
    public function getRequirements()
    {
        return static::REQUIREMENT_MOBILE;
    }

    /**
     * @inheritdoc
     */
    protected function createSingleShipmentRequest(ShipmentInterface $shipment)
    {
        $request = parent::createSingleShipmentRequest($shipment);

        $request->services = new EPrint\Model\StdServices();
        $request->services->contact = $this->createContact($shipment);

        return $request;
    }

    /**
     * @inheritdoc
     */
    protected function createMultiShipmentRequest(ShipmentInterface $shipment)
    {
        $request = parent::createMultiShipmentRequest($shipment);

        $request->services = new EPrint\Model\MultiServices();
        $request->services->contact = $this->createContact($shipment);

        return $request;
    }

    /**
     * Creates the predict contact.
     *
     * @param ShipmentInterface $shipment
     *
     * @return EPrint\Model\Contact
     */
    protected function createContact(ShipmentInterface $shipment)
    {
        $receiver = $this->addressResolver->resolveReceiverAddress($shipment, true);

        $contact = new EPrint\Model\Contact();
        $contact->type = EPrint\Enum\ETypeContact::PREDICT;
        $contact->sms = $this->formatPhoneNumber($receiver->getMobile());

        return $contact;
    }

    /**
     * @inheritdoc
     */
    public function supportShipment(Shipment\ShipmentDataInterface $shipment, $throw = true)
    {
        if (!parent::supportShipment($shipment, $throw)) {
            return false;
        }

        if ($shipment instanceof Shipment\ShipmentParcelInterface) {
            $shipment = $shipment->getShipment();
        }

        $receiver = $this->addressResolver->resolveReceiverAddress($shipment, true);

        if (!$receiver->getMobile()) {
            if ($throw) {
                $this->throwUnsupportedShipment($shipment, "Receiver address must have a mobile phone number.");
            }

            return false;
        }

        return true;
    }
}
