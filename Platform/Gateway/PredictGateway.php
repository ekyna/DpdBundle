<?php

declare(strict_types=1);

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
    public function getCapabilities(): int
    {
        return static::CAPABILITY_SHIPMENT;
    }

    public function getRequirements(): int
    {
        return static::REQUIREMENT_MOBILE;
    }

    protected function createSingleShipmentRequest(ShipmentInterface $shipment): EPrint\Request\StdShipmentLabelRequest
    {
        $request = parent::createSingleShipmentRequest($shipment);

        $request->services = new EPrint\Model\StdServices();
        $request->services->contact = $this->createContact($shipment);

        return $request;
    }

    /**
     * Creates the Predict contact.
     */
    protected function createContact(ShipmentInterface $shipment): EPrint\Model\Contact
    {
        $receiver = $this->addressResolver->resolveReceiverAddress($shipment, true);

        $contact = new EPrint\Model\Contact();
        $contact->type = EPrint\Enum\ETypeContact::PREDICT;
        $contact->sms = $this->formatPhoneNumber($receiver->getMobile());

        return $contact;
    }

    public function supportShipment(Shipment\ShipmentDataInterface $shipment, bool $throw = true): bool
    {
        if (!parent::supportShipment($shipment, $throw)) {
            return false;
        }

        /** @var Shipment\ShipmentInterface $shipment */

        $receiver = $this->addressResolver->resolveReceiverAddress($shipment, true);

        if (!$receiver->getMobile()) {
            if ($throw) {
                $this->throwUnsupportedShipment($shipment, 'Receiver address must have a mobile phone number.');
            }

            return false;
        }

        return true;
    }
}
