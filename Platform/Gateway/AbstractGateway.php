<?php

declare(strict_types=1);

namespace Ekyna\Bundle\DpdBundle\Platform\Gateway;

use Decimal\Decimal;
use Ekyna\Bundle\SettingBundle\Manager\SettingManagerInterface;
use Ekyna\Component\Commerce\Common\Model\AddressInterface;
use Ekyna\Component\Commerce\Common\Model\SaleAddressInterface;
use Ekyna\Component\Commerce\Exception\InvalidArgumentException;
use Ekyna\Component\Commerce\Exception\RuntimeException;
use Ekyna\Component\Commerce\Exception\ShipmentGatewayException;
use Ekyna\Component\Commerce\Exception\UnexpectedTypeException;
use Ekyna\Component\Commerce\Shipment\Gateway;
use Ekyna\Component\Commerce\Shipment\Model as Shipment;
use Ekyna\Component\Commerce\Shipment\Model\ShipmentInterface;
use Ekyna\Component\Dpd;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormInterface;

use function strlen;
use function Symfony\Component\Translation\t;

/**
 * Class AbstractGateway
 * @package Ekyna\Bundle\DpdBundle
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
abstract class AbstractGateway extends Gateway\AbstractGateway
{
    public const TRACK_URL = 'https://trace.dpd.fr/fr/trace/%s';
    public const PROVE_URL = 'https://trace.dpd.fr/preuvelivraison_%s';

    private const COUNTRY_CODES = [
        'AD', // Andorra
        'AT', // Austria
        'BE', // Belgium
        'BA', // Bosnia & Herzegovina
        'BG', // Bulgaria
        'HR', // Croatia
        'DK', // Denmark
        'ES', // Espagne
        'EE', // Estonia
        'FI', // Finland
        'FR', // France
        'GB', // United Kingdom
        'GR', // Greece
        'GG', // Guernsey
        'HU', // Hungary
        'IM', // Isle of Man
        'IE', // Ireland
        'IT', // Italy
        'JE', // Jersey
        'LV', // Latvia
        'LI', // Liechtenstein
        'LT', // Lithuania
        'LU', // Luxembourg
        'NO', // Norway
        'NL', // Netherlands
        'PL', // Poland
        'PT', // Portugal
        'CZ', // Czech Republic
        'RO', // Romania
        'RO', // Romania
        'RS', // Serbia
        'SK', // Slovakia
        'SI', // Slovenia
        'SE', // Sweden
        'CH', // Switzerland
    ];

    protected SettingManagerInterface $settingManager;

    private ?Dpd\EPrint\Api  $ePrintApi = null;
    private ?PhoneNumberUtil $phoneUtil = null;

    public function setSettingManager(SettingManagerInterface $settingManager): void
    {
        $this->settingManager = $settingManager;
    }

    public function ship(Shipment\ShipmentInterface $shipment): bool
    {
        $this->supportShipment($shipment);

        if ($this->hasTrackingNumber($shipment)) {
            return false;
        }

        if ($shipment->hasParcels()) {
            $success = $this->doMultiShipment($shipment);
        } else {
            $success = $this->doSingleShipment($shipment);
        }

        if (!$success) {
            return false;
        }

        $this->persister->persist($shipment);

        parent::ship($shipment);

        return true;
    }

    public function track(Shipment\ShipmentDataInterface $shipment): ?string
    {
        if (!$this->supportAction(Gateway\GatewayActions::TRACK)) {
            return null;
        }

        $this->supportShipment($shipment);

        if (!empty($number = $shipment->getTrackingNumber())) {
            return sprintf(static::TRACK_URL, $this->buildTrackNumber($number));
        }

        return null;
    }

    public function prove(Shipment\ShipmentDataInterface $shipment): ?string
    {
        if (!$this->supportAction(Gateway\GatewayActions::PROVE)) {
            return null;
        }

        $this->supportShipment($shipment);

        if (!empty($number = $shipment->getTrackingNumber())) {
            return sprintf(static::PROVE_URL, $this->buildTrackNumber($number));
        }

        return null;
    }

    private function buildTrackNumber(string $number): string
    {
        if (14 === strlen($number)) {
            return $number;
        }

        if (12 === strlen($number)) {
            return $this->config['country_code'] . $number;
        }

        return $this->config['country_code'] . $this->config['center_number'] . $number;
    }

    public function printLabel(Shipment\ShipmentDataInterface $shipment, array $types = null): array
    {
        $this->supportShipment($shipment);

        if ($shipment instanceof Shipment\ShipmentParcelInterface) {
            $s = $shipment->getShipment();
        } else {
            $s = $shipment;
        }

        /** @var Shipment\ShipmentInterface $s */
        $this->ship($s);

        if (empty($types)) {
            $types = $this->getDefaultLabelTypes();
        }

        $labels = [];

        if ($shipment instanceof Shipment\ShipmentInterface) {
            if ($shipment->hasParcels()) {
                foreach ($shipment->getParcels() as $parcel) {
                    $this->addShipmentLabel($labels, $parcel, $types);
                }
            } else {
                $this->addShipmentLabel($labels, $shipment, $types);
            }
        } elseif ($shipment instanceof Shipment\ShipmentParcelInterface) {
            $this->addShipmentLabel($labels, $shipment, $types);
        } else {
            throw new UnexpectedTypeException($shipment, [
                Shipment\ShipmentInterface::class,
                Shipment\ShipmentParcelInterface::class,
            ]);
        }

        return $labels;
    }

    public function buildForm(FormInterface $form): void
    {
        $form->add('insurance', CheckboxType::class, [
            'label'    => t('insurance', [], 'Dpd'),
            'attr'     => [
                'align_with_widget' => true,
            ],
            'required' => false,
        ]);
    }

    public function getActions(): array
    {
        return [
            Gateway\GatewayActions::SHIP,
            Gateway\GatewayActions::CANCEL,
            Gateway\GatewayActions::PRINT_LABEL,
            Gateway\GatewayActions::TRACK,
            Gateway\GatewayActions::PROVE,
        ];
    }

    public function getCapabilities(): int
    {
        return static::CAPABILITY_SHIPMENT | static::CAPABILITY_PARCEL;
    }

    public function getMaxWeight(): ?Decimal
    {
        return new Decimal(30);
    }

    /**
     * Returns the default label types.
     */
    protected function getDefaultLabelTypes(): array
    {
        return [Shipment\ShipmentLabelInterface::TYPE_SHIPMENT];
    }

    /**
     * Creates and adds the shipment label to the given list.
     */
    protected function addShipmentLabel(array &$labels, Shipment\ShipmentDataInterface $shipment, array $types): void
    {
        if (!$shipment->hasLabels() && !$this->doGetLabel($shipment)) {
            throw new RuntimeException('Failed to retrieve shipment label.');
        }

        foreach ($shipment->getLabels() as $label) {
            if (in_array($label->getType(), $types, true)) {
                $labels[] = $label;
            }
        }
    }

    /**
     * Performs get shipment details through DPD API.
     */
    protected function doGetShipment(Shipment\ShipmentDataInterface $shipment): ?Dpd\EPrint\Model\ShipmentDataExtendedBc
    {
        if (empty($number = $shipment->getTrackingNumber())) {
            throw new RuntimeException('Shipment (or parcel) must have its tracking number.');
        }

        $request = new Dpd\EPrint\Request\ShipmentRequestBc();
        $request->customer = $this->createCustomer();
        $request->BarcodeId = $number;

        try {
            $response = $this->getEPrintApi()->GetShipmentBc($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        return $response->GetShipmentBcResult;
    }

    /**
     * Performs get label details through DPD API.
     *
     * @return bool Whether the label has been set.
     */
    protected function doGetLabel(Shipment\ShipmentDataInterface $shipment): bool
    {
        if (empty($number = $shipment->getTrackingNumber())) {
            throw new RuntimeException('Shipment (or parcel) must have its tracking number.');
        }

        $request = new Dpd\EPrint\Request\ReceiveLabelBcRequest();
        $request->customer = $this->createCustomer();
        $request->labelType = $this->createLabelType();
        $request->shipmentNumber = $number;

        try {
            $response = $this->getEPrintApi()->GetLabelBc($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $result = $response->GetLabelBcResult;

        $labelConfig = $this->getLabelFormatAndSize($request->labelType);

        /** @var Dpd\EPrint\Model\Label $l */
        foreach ($result->labels as $l) {
            $type = $this->convertLabelType($l->type);

            // Existing label lookup
            foreach ($shipment->getLabels() as $label) {
                if ($label->getType() === $type) {
                    // Update content if needed
                    if ($label->getContent() !== $l->label) {
                        $label->setContent($l->label);
                    }

                    // Next DPD label
                    continue 2;
                }
            }

            // Create label
            $shipment->addLabel(
                $this->createLabel(
                    $l->label,
                    $type,
                    $labelConfig['format'],
                    $labelConfig['size']
                )
            );
        }

        $s = $shipment instanceof Shipment\ShipmentParcelInterface ? $shipment->getShipment() : $shipment;

        $this->persister->persist($s);

        return true;
    }

    /**
     * Performs single shipment through DPD API.
     *
     * @return bool Whether the operation succeeded.
     */
    protected function doSingleShipment(Shipment\ShipmentInterface $shipment): bool
    {
        $request = $this->createSingleShipmentRequest($shipment);

        try {
            $response = $this->getEPrintApi()->CreateShipmentWithLabelsBc($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $result = $response->CreateShipmentWithLabelsBcResult;

        // Tracking number
        /** @var Dpd\EPrint\Model\ShipmentBc|false $current */
        $current = $result->shipments->getIterator()->current();
        if (false === $current) {
            return false;
        }
        $shipment->setTrackingNumber($current->Shipment->BarcodeId);

        $labelConfig = $this->getLabelFormatAndSize($request->labelType);

        // Shipment labels
        /** @var Dpd\EPrint\Model\Label $l */
        foreach ($result->labels as $l) {
            $shipment->addLabel(
                $this->createLabel(
                    $l->label,
                    $this->convertLabelType($l->type),
                    $labelConfig['format'],
                    $labelConfig['size']
                )
            );
        }

        return true;
    }

    /**
     * Performs multi (with parcels) shipment through DPD API.
     *
     * @return bool Whether the operation succeeded.
     */
    protected function doMultiShipment(Shipment\ShipmentInterface $shipment): bool
    {
        $request = $this->createMultiShipmentRequest($shipment);

        try {
            $response = $this->getEPrintApi()->CreateMultiShipmentBc($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $result = $response->CreateMultiShipmentBcResult;

        $index = 0;
        foreach ($result->shipments as $s) {
            /** @var Shipment\ShipmentParcelInterface $parcel */
            if (null === $parcel = $shipment->getParcels()->get($index)) {
                throw new RuntimeException('Inconsistency between response\'s slaves and shipment\'s parcels.');
            }

            $parcel->setTrackingNumber($s->Shipment->BarcodeId);

            if (!$this->doGetLabel($parcel)) {
                throw new RuntimeException('Failed to retrieve shipment label.');
            }

            $index++;
        }

        if (!$this->hasTrackingNumber($shipment)) {
            throw new RuntimeException('Failed to set all parcel\'s tracking numbers.');
        }

        return true;
    }

    /**
     * Creates the shipment with labels request.
     */
    protected function createSingleShipmentRequest(
        Shipment\ShipmentInterface $shipment
    ): Dpd\EPrint\Request\StdShipmentLabelRequest {
        if ($shipment->hasParcels()) {
            throw new InvalidArgumentException('Expected shipment without parcel.');
        }

        $request = new Dpd\EPrint\Request\StdShipmentLabelRequest();
        $request->customer_centernumber = $this->config['center_number'];
        $request->customer_countrycode = $this->config['country_code'];
        $request->customer_number = $this->config['customer_number'];

        // (Optional) Label type: PNG, PDF, PDF_A6
        $request->labelType = $this->createLabelType();

        // Receiver address
        $receiver = $this->addressResolver->resolveReceiverAddress($shipment, true);
        $request->receiveraddress = $this->createAddress($receiver);

        // (Optional) Receiver address optional info
        $request->receiverinfo = $this->createAddressInfo($receiver);

        // Shipper address
        $shipper = $this->addressResolver->resolveSenderAddress($shipment, true);
        $request->shipperaddress = $this->createAddress($shipper);

        // Shipment weight
        if (0 >= $weight = $shipment->getWeight()) {
            $weight = $this->weightCalculator->calculateShipment($shipment);
        }
        $request->weight = $weight->toFixed(2); // kg

        // (Optional) Contact
        $request->services = new Dpd\EPrint\Model\StdServices();
        $request->services->contact = $this->createContact($shipment);

        // (Optional) Theoretical shipment date ('d/m/Y' or 'd.m.Y')
        $request->shippingdate = date('d/m/Y');

        // (Optional) References and comment
        $request->referencenumber = $shipment->getNumber();
        $request->reference2 = $shipment->getSale()->getNumber();

        $data = $shipment->getGatewayData();
        if (isset($data['insurance']) && $data['insurance']) {
            $request->services->extraInsurance = $this->createExtraInsurance($shipment);
        }

        // TODO $request->customLabelText = 'Shipping comment...';

        return $request;
    }

    /**
     * Creates the DPD extra insurance object.
     */
    protected function createExtraInsurance(Shipment\ShipmentDataInterface $shipment): Dpd\EPrint\Model\ExtraInsurance
    {
        $value = $shipment->getValorization();
        if (0 >= $value) {
            if ($shipment instanceof Shipment\ShipmentInterface) {
                $value = $this->calculateGoodsValue($shipment);
            } elseif ($shipment instanceof Shipment\ShipmentParcelInterface) {
                throw new ShipmentGatewayException('Parcel\'s valorization must be set.');
            } else {
                throw new InvalidArgumentException('Expected shipment or parcel');
            }
        }

        $insurance = new Dpd\EPrint\Model\ExtraInsurance();

        $insurance->type = Dpd\EPrint\Enum\ETypeInsurance::BY_SHIPMENTS;
        $insurance->value = $value->toFixed(2);

        return $insurance;
    }

    /**
     * Creates the multi shipment request.
     */
    protected function createMultiShipmentRequest(
        Shipment\ShipmentInterface $shipment
    ): Dpd\EPrint\Request\MultiShipmentRequest {
        if (!$shipment->hasParcels()) {
            throw new InvalidArgumentException('Expected shipment with parcels.');
        }

        $request = new Dpd\EPrint\Request\MultiShipmentRequest();
        $request->customer_centernumber = $this->config['center_number'];
        $request->customer_countrycode = $this->config['country_code'];
        $request->customer_number = $this->config['customer_number'];

        // Receiver address
        $receiver = $this->addressResolver->resolveReceiverAddress($shipment);
        $request->receiveraddress = $this->createAddress($receiver);

        // (Optional) Receiver address optional info
        $request->receiverinfo = $this->createAddressInfo($receiver);

        // Shipper address
        $shipper = $this->addressResolver->resolveSenderAddress($shipment);
        $request->shipperaddress = $this->createAddress($shipper);

        // (Optional) Theoretical shipment date ('d/m/Y' or 'd.m.Y')
        $request->shippingdate = date('d/m/Y');

        // (Optional) Contact
        $request->services = new Dpd\EPrint\Model\MultiServices();
        $request->services->contact = $this->createContact($shipment);

        $data = $shipment->getGatewayData();

        $addInsurance = isset($data['insurance']) && $data['insurance'];

        $index = 1;
        foreach ($shipment->getParcels() as $parcel) {
            $weight = $parcel->getWeight() ?: new Decimal(0);
            $slave = new Dpd\EPrint\Model\SlaveRequest();
            $slave->weight = $weight->toFixed(2); // kg
            $slave->referencenumber = $shipment->getNumber() . '_' . $index;
            $slave->reference2 = $shipment->getSale()->getNumber();
            $slave->reference3 = 'parcel#' . $parcel->getId();

            if ($addInsurance) {
                $slave->services = new Dpd\EPrint\Model\SlaveServices();
                $slave->services->extraInsurance = $this->createExtraInsurance($parcel);
            }

            $request->addSlave($slave);

            $index++;
        }

        return $request;
    }

    /**
     * Creates a EPrint address from the given component address.
     */
    protected function createAddress(
        AddressInterface         $address,
        Dpd\EPrint\Model\Address $target = null
    ): Dpd\EPrint\Model\Address {
        if (null === $target) {
            $target = new Dpd\EPrint\Model\Address();
        }

        if ($address->getCompany()) {
            $target->name = $address->getCompany();
        } elseif ($address->getFirstName() && $address->getLastName()) {
            $target->name = $address->getFirstName() . ' ' . $address->getLastName();
        } elseif ($address instanceof SaleAddressInterface && $sale = $address->getSale()) {
            if ($sale->getCompany()) {
                $target->name = $sale->getCompany();
            } elseif ($sale->getFirstName() && $sale->getLastName()) {
                $target->name = $sale->getFirstName() . ' ' . $sale->getLastName();
            }
        }

        $code = $address->getCountry()->getCode();
        if (!in_array($code, self::COUNTRY_CODES, true)) {
            $code = 'INT'; // Intercontinental
        }

        $target->countryPrefix = $code;
        $target->zipCode = str_replace(' ', '', $address->getPostalCode()); // DPD don't like spaces :(
        $target->city = $address->getCity();
        $target->street = $address->getStreet();

        if (null === $phone = $address->getPhone()) {
            $phone = $address->getMobile();
        }
        if (null !== $phone) {
            $phone = $this->formatPhoneNumber($phone);
        }
        $target->phoneNumber = $phone;

        return $target;
    }

    /**
     * Creates EPrint address info from the given component address.
     */
    protected function createAddressInfo(AddressInterface $address): ?Dpd\EPrint\Model\AddressInfo
    {
        $info = new Dpd\EPrint\Model\AddressInfo();
        $empty = true;

        if ($address->getFirstName() && $address->getLastName() && $address->getCompany()) {
            $info->name2 = $address->getFirstName() . ' ' . $address->getLastName();
            $empty = false;
        }
        if ($address->getComplement()) {
            $info->vinfo1 = $address->getComplement();
            $empty = false;
        }
        if ($address->getSupplement()) {
            $info->vinfo2 = $address->getSupplement();
            $empty = false;
        }
        if ($address->getExtra()) {
            $info->name3 = $address->getExtra();
            $empty = false;
        }
        if ($address->getDigicode1()) {
            $info->digicode1 = $address->getDigicode1();
            $empty = false;
        }
        if ($address->getDigicode2()) {
            $info->digicode2 = $address->getDigicode2();
            $empty = false;
        }
        if ($address->getIntercom()) {
            $info->intercomid = $address->getIntercom();
            $empty = false;
        }

        return $empty ? null : $info;
    }

    /**
     * Creates EPrint customer.
     */
    protected function createCustomer(): Dpd\EPrint\Model\Customer
    {
        $customer = new Dpd\EPrint\Model\Customer();

        $customer->number = $this->config['customer_number'];
        $customer->countrycode = $this->config['country_code'];
        $customer->centernumber = $this->config['center_number'];

        return $customer;
    }

    /**
     * Creates the contact.
     */
    protected function createContact(ShipmentInterface $shipment): Dpd\EPrint\Model\Contact
    {
        $contact = new Dpd\EPrint\Model\Contact();
        $contact->type = Dpd\EPrint\Enum\ETypeContact::AUTOMATIC_MAIL;
        $contact->email = $shipment->getSale()->getEmail();

        $receiver = $this->addressResolver->resolveReceiverAddress($shipment, true);

        if (null !== $mobile = $receiver->getMobile()) {
            $contact->sms = $this->formatPhoneNumber($mobile);
        }

        return $contact;
    }

    /**
     * Creates EPrint parcel from the given component shipment data.
     */
    protected function createParcel(Shipment\ShipmentDataInterface $shipment): Dpd\EPrint\Model\Parcel
    {
        if (empty($shipment->getTrackingNumber())) {
            throw new RuntimeException('Shipment (or parcel) must have its tracking number.');
        }

        $parcel = new Dpd\EPrint\Model\Parcel();

        $parcel->parcelnumber = $shipment->getTrackingNumber();
        $parcel->countrycode = $this->config['country_code'];
        $parcel->centernumber = $this->config['center_number'];

        return $parcel;
    }

    /**
     * Creates a EPrint label type.
     */
    protected function createLabelType(): Dpd\EPrint\Model\LabelType
    {
        $labelType = new Dpd\EPrint\Model\LabelType();
        $labelType->type = $this->config['label_type'];

        return $labelType;
    }

    /**
     * @param Dpd\EPrint\Model\LabelType $type
     * @return array{format: string, size: string}
     */
    protected function getLabelFormatAndSize(Dpd\EPrint\Model\LabelType $type): array
    {
        $format = match ($type->type) {
            Dpd\EPrint\Enum\ELabelType::PNG       => Shipment\ShipmentLabelInterface::FORMAT_PNG,
            Dpd\EPrint\Enum\ELabelType::PDF,
            Dpd\EPrint\Enum\ELabelType::PDF_A6    => Shipment\ShipmentLabelInterface::FORMAT_PDF,
            Dpd\EPrint\Enum\ELabelType::ZPL,
            Dpd\EPrint\Enum\ELabelType::ZPL300,
            Dpd\EPrint\Enum\ELabelType::ZPL_A6,
            Dpd\EPrint\Enum\ELabelType::ZPL300_A6 => Shipment\ShipmentLabelInterface::FORMAT_ZPL,
            Dpd\EPrint\Enum\ELabelType::EPL       => Shipment\ShipmentLabelInterface::FORMAT_EPL,
        };

        $size = match ($type->type) {
            Dpd\EPrint\Enum\ELabelType::PDF,   => Shipment\ShipmentLabelInterface::SIZE_A4,
            Dpd\EPrint\Enum\ELabelType::PNG,
            Dpd\EPrint\Enum\ELabelType::ZPL,
            Dpd\EPrint\Enum\ELabelType::ZPL300,
            Dpd\EPrint\Enum\ELabelType::EPL    => Shipment\ShipmentLabelInterface::SIZE_A5,
            Dpd\EPrint\Enum\ELabelType::ZPL_A6,
            Dpd\EPrint\Enum\ELabelType::ZPL300_A6,
            Dpd\EPrint\Enum\ELabelType::PDF_A6 => Shipment\ShipmentLabelInterface::SIZE_A6
        };

        return ['format' => $format, 'size' => $size];
    }

    /**
     * Converts the DPD label type to the commerce label type.
     */
    protected function convertLabelType(string $type): string
    {
        return match ($type) {
            Dpd\EPrint\Enum\EType::REVERSE,
            Dpd\EPrint\Enum\EType::REVERSEBIC3       => Shipment\ShipmentLabelInterface::TYPE_RETURN,
            Dpd\EPrint\Enum\EType::PROOF,
            Dpd\EPrint\Enum\EType::PROOFBIC3         => Shipment\ShipmentLabelInterface::TYPE_PROOF,
            Dpd\EPrint\Enum\EType::EPRINT_ATTACHMENT => Shipment\ShipmentLabelInterface::TYPE_SUMMARY,
            default                                  => Shipment\ShipmentLabelInterface::TYPE_SHIPMENT,
        };
    }

    /**
     * Returns the EPrint api.
     */
    protected function getEPrintApi(): Dpd\EPrint\Api
    {
        if (null !== $this->ePrintApi) {
            return $this->ePrintApi;
        }

        return $this->ePrintApi = new Dpd\EPrint\Api([
            'login'     => $this->config['eprint']['login'],
            'password'  => $this->config['eprint']['password'],
            'cache'     => $this->config['cache'],
            'debug'     => $this->config['debug'],
            'test'      => $this->config['test'],
            'ssl_check' => $this->config['ssl_check'],
        ]);
    }

    /**
     * Formats the phone number.
     *
     * @param PhoneNumber|string|null $number
     *
     * @return string
     */
    protected function formatPhoneNumber(PhoneNumber|string|null $number): string
    {
        if ($number instanceof PhoneNumber) {
            if (null === $this->phoneUtil) {
                $this->phoneUtil = PhoneNumberUtil::getInstance();
            }

            return $this->phoneUtil->format($number, PhoneNumberFormat::NATIONAL);
        }

        return (string)$number;
    }
}
