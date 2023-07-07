<?php

declare(strict_types=1);

namespace Ekyna\Bundle\DpdBundle\Platform\Gateway;

use DateTime;
use Ekyna\Component\Commerce\Exception\InvalidArgumentException;
use Ekyna\Component\Commerce\Exception\ShipmentGatewayException;
use Ekyna\Component\Commerce\Shipment\Gateway;
use Ekyna\Component\Commerce\Shipment\Model as Shipment;
use Ekyna\Component\Dpd;
use Symfony\Component\Form\Extension\Core\Type;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints as Assert;

use function Symfony\Component\Translation\t;

/**
 * Class ReturnGateway
 * @package Ekyna\Bundle\DpdBundle
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
class ReturnGateway extends AbstractGateway
{
    public function getActions(): array
    {
        return [
            Gateway\GatewayActions::SHIP,
            Gateway\GatewayActions::CANCEL,
            Gateway\GatewayActions::COMPLETE,
            //Gateway\GatewayActions::PRINT_LABEL, // NO LABELS
            Gateway\GatewayActions::TRACK,
        ];
    }

    public function cancel(Shipment\ShipmentInterface $shipment): bool
    {
        $this->supportShipment($shipment);

        $result = false;
        if ($shipment->getState() === Shipment\ShipmentStates::STATE_PENDING) {
            if ($this->doCancelShipment($shipment)) {
                $this->persister->persist($shipment);

                $result = true;
            }
        }

        return parent::cancel($shipment) || $result;
    }

    public function buildForm(FormInterface $form): void
    {
        parent::buildForm($form);

        $form
            ->add('parcel_count', Type\IntegerType::class, [
                'label'       => t('parcel_count', [], 'Dpd'),
                'required'    => true,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Type('int'),
                    new Assert\Range([
                        'min' => 1,
                        'max' => 20,
                    ]),
                ],
            ])
            ->add('pick_date', Type\DateTimeType::class, [
                'label'              => 'pick_date',
                'input'              => 'string',
                'data_class'         => null,
                'translation_domain' => 'Dpd',
                'required'           => true,
                'constraints'        => [
                    new Assert\NotBlank(),
                    new Assert\DateTime(),
                ],
            ])
            ->add('time_from', Type\TextType::class, [
                'label'       => t('time_from', [], 'Dpd'),
                'required'    => false,
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '~^[0-2][0-9]:[0-5][0-9]$~',
                    ]),
                ],
            ])
            ->add('time_to', Type\TextType::class, [
                'label'       => t('time_to', [], 'Dpd'),
                'required'    => false,
                'constraints' => [
                    new Assert\Regex([
                        'pattern' => '~^[0-2][0-9]:[0-5][0-9]$~',
                    ]),
                ],
            ])
            ->add('remark', Type\TextType::class, [
                'label'    => t('remark', [], 'Dpd'),
                'required' => false,
            ])
            ->add('pick_remark', Type\TextType::class, [
                'label'    => t('pick_remark', [], 'Dpd'),
                'required' => false,
            ])
            ->add('delivery_remark', Type\TextType::class, [
                'label'    => t('delivery_remark', [], 'Dpd'),
                'required' => false,
            ])// TODO contact type (sms/email)
        ;
    }

    protected function doSingleShipment(Shipment\ShipmentInterface $shipment): bool
    {
        if ($shipment->hasParcels()) {
            throw new InvalidArgumentException('Expected shipment without parcel.');
        }
        if (!$shipment->isReturn()) {
            throw new InvalidArgumentException('Expected return shipment.');
        }

        $request = $this->createCollectionRequest($shipment);

        try {
            $response = $this->getEPrintApi()->CreateCollectionRequestBc($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $shipments = $response->CreateCollectionRequestBcResult;

        // Tracking number
        $current = $shipments->getIterator()->current();
        /** @var Dpd\EPrint\Model\ShipmentBc|false $current */
        if (false === $current) {
            return false;
        }
        $shipment->setTrackingNumber($current->Shipment->BarcodeId);

        return true;
    }

    /**
     * Creates the collection request.
     */
    protected function createCollectionRequest(
        Shipment\ShipmentInterface $shipment
    ): Dpd\EPrint\Request\CollectionRequestRequest {
        if (!$shipment->isReturn()) {
            throw new InvalidArgumentException('Expected return shipment.');
        }

        $request = new Dpd\EPrint\Request\CollectionRequestRequest();
        $request->customer_centernumber = $this->config['center_number'];
        $request->customer_countrycode = $this->config['country_code'];
        $request->customer_number = $this->config['customer_number'];

        // Receiver address
        $receiver = $this->addressResolver->resolveReceiverAddress($shipment, true);
        $request->receiveraddress = $this->createAddress($receiver);

        // Shipper address
        $shipper = $this->addressResolver->resolveSenderAddress($shipment, true);
        $request->shipperaddress = $this->createAddress($shipper);

        $sale = $shipment->getSale();
        if (null === $mobile = $shipper->getMobile()) {
            if (null !== $customer = $sale->getCustomer()) {
                $mobile = $customer->getMobile();
            }
        }

        // Services
        $request->services = new Dpd\EPrint\Model\CollectionRequestServices();
        $request->services->contact = new Dpd\EPrint\Model\ContactCollectionRequest();
        $request->services->contact->type = Dpd\EPrint\Enum\ETypeContact::AUTOMATIC_MAIL;
        $request->services->contact->email = $this->settingManager->getParameter('general.admin_email');
        $request->services->contact->shipper_email = $sale->getEmail();
        if (!empty($mobile)) {
            $request->services->contact->shipper_mobil = $this->formatPhoneNumber($mobile);
        }

        $data = array_replace([
            'insurance'       => 0,
            'parcel_count'    => 1,
            'pick_date'       => null,
            'time_from'       => '09:00',
            'time_to'         => '12:00',
            'remark'          => '',
            'pick_remark'     => '',
            'delivery_remark' => '',
        ],
            array_filter($shipment->getGatewayData(), function ($value) {
                return !empty($value);
            }));

        if ($data['insurance']) {
            $request->services->extraInsurance = $this->createExtraInsurance($shipment);
        }

        $request->parcel_count = intval($data['parcel_count']);
        $request->pick_date = (new DateTime($data['pick_date']))->format('d/m/Y'); // Pick date ('d/m/Y' or 'd.m.Y')
        $request->time_from = $data['time_from'];
        $request->time_to = $data['time_to'];
        $request->remark = $data['remark'];
        $request->pick_remark = $data['pick_remark'];
        $request->delivery_remark = $data['delivery_remark'];

        // (Optional) References and comment
        $request->referencenumber = $shipment->getNumber();
        $request->reference2 = $shipment->getSale()->getNumber();

        // TODO $request->customLabelText = 'Shipping comment...';

        return $request;
    }

    /**
     * Cancels the return through DPD Api.
     */
    protected function doCancelShipment(Shipment\ShipmentInterface $shipment): bool
    {
        // Shipment request
        $request = new Dpd\EPrint\Request\TerminateCollectionRequestBcRequest();
        $request->parcel = $this->createParcel($shipment);
        $request->customer = $this->createCustomer();

        try {
            $this->getEPrintApi()->TerminateCollectionRequestBc($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        return true;
    }

    protected function getDefaultLabelTypes(): array
    {
        return [
            Shipment\ShipmentLabelInterface::TYPE_RETURN,
        ];
    }

    public function getCapabilities(): int
    {
        return static::CAPABILITY_RETURN;
    }
}
