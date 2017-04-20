<?php

declare(strict_types=1);

namespace Ekyna\Bundle\DpdBundle\Platform\Gateway;

use DateTime;
use Ekyna\Component\Commerce\Exception\InvalidArgumentException;
use Ekyna\Component\Commerce\Exception\ShipmentGatewayException;
use Ekyna\Component\Commerce\Shipment\Model as Shipment;
use Ekyna\Component\Commerce\Shipment\Gateway;
use Ekyna\Component\Dpd;
use Symfony\Component\Form\FormInterface;

/**
 * Class RelayReturnGateway
 * @package Ekyna\Bundle\DpdBundle\Platform\Gateway
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
class RelayReturnGateway extends AbstractRelayGateway
{
    public function buildForm(FormInterface $form): void
    {

    }

    protected function doSingleShipment(Shipment\ShipmentInterface $shipment): bool
    {
        $request = $this->createInverseShipmentRequest($shipment);

        try {
            $response = $this->getEPrintApi()->CreateReverseInverseShipmentWithLabels($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $result = $response->CreateReverseInverseShipmentWithLabelsResult;

        // Tracking number
        $shipment->setTrackingNumber((string)$result->shipment->parcelnumber);

        // Shipment labels
        foreach ($result->labels as $l) {
            $shipment->addLabel(
                $this->createLabel(
                    $l->label,
                    $this->convertLabelType($l->type),
                    Shipment\ShipmentLabelInterface::FORMAT_PNG,
                    Shipment\ShipmentLabelInterface::SIZE_A5
                )
            );
        }

        return true;
    }

    /**
     * Creates the reverse inverse shipment with labels request.
     */
    protected function createInverseShipmentRequest(
        Shipment\ShipmentInterface $shipment
    ): Dpd\EPrint\Request\ReverseShipmentLabelRequest {
        if ($shipment->hasParcels()) {
            throw new InvalidArgumentException('Expected shipment without parcel.');
        }
        if (!$shipment->isReturn()) {
            throw new InvalidArgumentException('Expected return shipment.');
        }
        if (!$shipment->getRelayPoint()) {
            throw new InvalidArgumentException('Expected return shipment with relay point.');
        }

        $request = new Dpd\EPrint\Request\ReverseShipmentLabelRequest();
        $request->customer_centernumber = $this->config['center_number'];
        $request->customer_countrycode = $this->config['country_code'];
        $request->customer_number = $this->config['customer_number'];

        // Services
        // Insurance is not supported according to the documentation
        //$request->services = new Dpd\EPrint\Model\ReverseInverseServices();

        // (Optional) Label type: PNG, PDF, PDF_A6
        $request->labelType = $this->createLabelType();

        // Receiver address
        $receiver = $this->addressResolver->resolveReceiverAddress($shipment, true);
        $request->receiveraddress = $this->createAddress($receiver);

        // (Optional) Receiver address optional info
        $request->receiverinfo = $this->createAddressInfo($receiver);

        // Shipper address
        $shipper = $this->addressResolver->resolveSenderAddress($shipment, true); // NO relay point
        $request->shipperaddress = $this->createAddress($shipper);

        // Shipment weight
        if (0 >= $weight = $shipment->getWeight()) {
            $weight = $this->weightCalculator->calculateShipment($shipment);
        }
        $request->weight = $weight->toFixed(2); // kg
        $request->expire_offset = 15; // days (from shippingdate, min 7)
        $request->refasbarcode = true;

        // (Optional) Theoretical shipment date ('d/m/Y' or 'd.m.Y')
        $request->shippingdate = (new DateTime('now'))->format('d/m/Y');

        // (Optional) Reference
        $request->referencenumber = $shipment->getNumber();

        return $request;
    }

    protected function getDefaultLabelTypes(): array
    {
        return [
            Shipment\ShipmentLabelInterface::TYPE_RETURN,
            Shipment\ShipmentLabelInterface::TYPE_PROOF,
        ];
    }

    public function getActions(): array
    {
        return [
            Gateway\GatewayActions::SHIP,
            Gateway\GatewayActions::CANCEL,
            Gateway\GatewayActions::COMPLETE,
            Gateway\GatewayActions::PRINT_LABEL,
            Gateway\GatewayActions::TRACK,
            Gateway\GatewayActions::LIST_RELAY_POINTS,
            Gateway\GatewayActions::GET_RELAY_POINT,
        ];
    }

    public function getCapabilities(): int
    {
        return static::CAPABILITY_RETURN | static::CAPABILITY_RELAY;
    }
}
