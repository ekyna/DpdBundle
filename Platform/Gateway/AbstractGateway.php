<?php

namespace Ekyna\Bundle\DpdBundle\Platform\Gateway;

use Ekyna\Bundle\CommerceBundle\Service\ConstantsHelper;
use Ekyna\Component\Commerce\Common\Model\AddressInterface;
use Ekyna\Component\Commerce\Exception\InvalidArgumentException;
use Ekyna\Component\Commerce\Exception\RuntimeException;
use Ekyna\Component\Commerce\Exception\ShipmentGatewayException;
use Ekyna\Component\Commerce\Order\Entity\OrderShipmentLabel;
use Ekyna\Component\Dpd;
use Ekyna\Bundle\SettingBundle\Manager\SettingsManagerInterface;
use Ekyna\Component\Commerce\Shipment\Gateway;
use Ekyna\Component\Commerce\Shipment\Model as Shipment;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormInterface;

/**
 * Class AbstractGateway
 * @package Ekyna\Bundle\DpdBundle
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
abstract class AbstractGateway extends Gateway\AbstractGateway
{
    const TRACK_URL = 'http://www.dpd.fr/traces_%s';
    const PROVE_URL = 'http://www.dpd.fr/preuvelivraison_%s';

    /**
     * @var SettingsManagerInterface
     */
    protected $settingManager;

    /**
     * @var ConstantsHelper
     */
    protected $constantsHelper;

    /**
     * @var Dpd\EPrint\Api
     */
    private $ePrintApi;

    /**
     * @var PhoneNumberUtil
     */
    private $phoneUtil;


    /**
     * Sets the setting manager.
     *
     * @param SettingsManagerInterface $settingManager
     */
    public function setSettingManager(SettingsManagerInterface $settingManager)
    {
        $this->settingManager = $settingManager;
    }

    /**
     * Sets the constants helper.
     *
     * @param ConstantsHelper $constantsHelper
     */
    public function setConstantsHelper(ConstantsHelper $constantsHelper)
    {
        $this->constantsHelper = $constantsHelper;
    }

    /**
     * @inheritDoc
     */
    public function ship(Shipment\ShipmentInterface $shipment)
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

    /**
     * @inheritDoc
     */
    public function track(Shipment\ShipmentDataInterface $shipment)
    {
        if (!$this->supportAction(Gateway\GatewayActions::TRACK)) {
            return null;
        }

        $this->supportShipment($shipment);

        if (!empty($number = $shipment->getTrackingNumber())) {
            return sprintf(static::TRACK_URL, $this->config['country_code'] . $this->config['center_number'] . $number);
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function prove(Shipment\ShipmentDataInterface $shipment)
    {
        if (!$this->supportAction(Gateway\GatewayActions::PROVE)) {
            return null;
        }

        $this->supportShipment($shipment);

        if (!empty($number = $shipment->getTrackingNumber())) {
            return sprintf(static::PROVE_URL, $this->config['country_code'] . $this->config['center_number'] . $number);
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function printLabel(Shipment\ShipmentDataInterface $shipment, array $types = null)
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
            throw new InvalidArgumentException();
        }

        return $labels;
    }

    /**
     * @inheritDoc
     */
    public function buildForm(FormInterface $form)
    {
        $form->add('insurance', CheckboxType::class, [
            'label'              => 'insurance',
            'translation_domain' => 'Dpd',
            'attr'               => [
                'align_with_widget' => true,
            ],
            'required'           => false,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getActions()
    {
        return [
            Gateway\GatewayActions::SHIP,
            Gateway\GatewayActions::CANCEL,
            Gateway\GatewayActions::PRINT_LABEL,
            Gateway\GatewayActions::TRACK,
            Gateway\GatewayActions::PROVE,
        ];
    }

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
    public function getMaxWeight()
    {
        return 30;
    }

    /**
     * Returns the default label types.
     *
     * @return array
     */
    protected function getDefaultLabelTypes()
    {
        return [Shipment\ShipmentLabelInterface::TYPE_SHIPMENT];
    }

    /**
     * Creates and adds the shipment label to the given list.
     *
     * @param array                          $labels
     * @param Shipment\ShipmentDataInterface $shipment
     * @param array                          $types
     */
    protected function addShipmentLabel(array &$labels, Shipment\ShipmentDataInterface $shipment, array $types)
    {
        if (!$shipment->hasLabels() && !$this->doGetLabel($shipment)) {
            throw new RuntimeException("Failed to retrieve shipment label.");
        }

        foreach ($shipment->getLabels() as $label) {
            if (in_array($label->getType(), $types, true)) {
                $labels[] = $label;
            }
        }
    }

    /**
     * Performs get shipment details through DPD API.
     *
     * @param Shipment\ShipmentDataInterface $shipment
     *
     * @return Dpd\EPrint\Model\ShipmentDataExtended|null
     */
    protected function doGetShipment(Shipment\ShipmentDataInterface $shipment)
    {
        $request = new Dpd\EPrint\Request\ShipmentRequest();
        $request->parcel = $this->createParcel($shipment);
        $request->customer = $this->createCustomer();

        try {
            $response = $this->getEPrintApi()->GetShipment($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        return $response->GetShipmentResult;
    }

    /**
     * Performs get label details through DPD API.
     *
     * @param Shipment\ShipmentDataInterface $shipment
     *
     * @return bool Whether the label has been set.
     */
    protected function doGetLabel(Shipment\ShipmentDataInterface $shipment)
    {
        $request = new Dpd\EPrint\Request\ReceiveLabelRequest();

        $request->parcelnumber = $shipment->getTrackingNumber();
        $request->countrycode = $this->config['country_code'];
        $request->centernumber = $this->config['center_number'];
        $request->labelType = $this->createLabelType();

        try {
            $response = $this->getEPrintApi()->GetLabel($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $result = $response->GetLabelResult;

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
                    OrderShipmentLabel::FORMAT_PNG,
                    OrderShipmentLabel::SIZE_A6
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
     * @param Shipment\ShipmentInterface $shipment
     *
     * @return bool Whether the operation succeeded.
     */
    protected function doSingleShipment(Shipment\ShipmentInterface $shipment)
    {
        $request = $this->createSingleShipmentRequest($shipment);

        try {
            $response = $this->getEPrintApi()->CreateShipmentWithLabels($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $result = $response->CreateShipmentWithLabelsResult;

        // Tracking number
        /** @var Dpd\EPrint\Model\Shipment $s */
        if (false === $s = current($result->shipments)) {
            return false;
        }
        $shipment->setTrackingNumber($s->parcelnumber);

        // Shipment labels
        /** @var Dpd\EPrint\Model\Label $l */
        foreach ($result->labels as $l) {
            $shipment->addLabel(
                $this->createLabel(
                    $l->label,
                    $this->convertLabelType($l->type),
                    OrderShipmentLabel::FORMAT_PNG,
                    OrderShipmentLabel::SIZE_A6
                )
            );
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    protected function createLabel($content, $type, $format, $size)
    {
        if (false === $img = imagecreatefromstring($content)) {
            throw new RuntimeException("Unexpected label image data.");
        }

        // Rotate 270°
        $img = imagerotate($img, 270, 0);
        // Crop
        $img = imagecrop($img, ['x' => 23, 'y' => 36, 'width' => 783, 'height' => 1255]);

        ob_start();
        imagegif($img);
        $content = ob_get_contents();
        ob_end_clean();
        imagedestroy($img);

        return parent::createLabel($content, $type, $format, $size);
    }

    /**
     * Performs multi (with parcels) shipment through DPD API.
     *
     * @param Shipment\ShipmentInterface $shipment
     *
     * @return bool Whether the operation succeeded.
     */
    protected function doMultiShipment(Shipment\ShipmentInterface $shipment)
    {
        $request = $this->createMultiShipmentRequest($shipment);

        try {
            $response = $this->getEPrintApi()->CreateMultiShipment($request);
        } catch (Dpd\Exception\ExceptionInterface $e) {
            throw new ShipmentGatewayException($e->getMessage(), $e->getCode(), $e);
        }

        $result = $response->CreateMultiShipmentResult;

        /** @var Dpd\EPrint\Model\Shipment $s */
        $index = 0;
        foreach ($result->shipments as $s) {
            /** @var Shipment\ShipmentParcelInterface $parcel */
            if (null === $parcel = $shipment->getParcels()->get($index)) {
                throw new RuntimeException("Inconsistency between response's slaves and shipment's parcels.");
            }

            $parcel->setTrackingNumber($s->parcelnumber);

            if (!$this->doGetLabel($parcel)) {
                throw new RuntimeException("Failed to retrieve shipment label.");
            }

            $index++;
        }

        if (!$this->hasTrackingNumber($shipment)) {
            throw new RuntimeException("Failed to set all parcel's tracking numbers.");
        }

        return true;
    }

    /**
     * Creates the shipment with labels request.
     *
     * @param Shipment\ShipmentInterface $shipment
     *
     * @return Dpd\EPrint\Request\StdShipmentLabelRequest
     */
    protected function createSingleShipmentRequest(Shipment\ShipmentInterface $shipment)
    {
        if ($shipment->hasParcels()) {
            throw new InvalidArgumentException("Expected shipment without parcel.");
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
        $request->weight = round($weight, 2); // kg

        // (Optional) Theoretical shipment date ('d/m/Y' or 'd.m.Y')
        $request->shippingdate = date('d/m/Y');

        // (Optional) References and comment
        $request->referencenumber = $shipment->getNumber();
        $request->reference2 = $shipment->getSale()->getNumber();

        $data = $shipment->getGatewayData();
        if (isset($data['insurance']) && $data['insurance']) {
            $request->services = new Dpd\EPrint\Model\StdServices();
            $request->services->extraInsurance = $this->createExtraInsurance($shipment);
        }

        // TODO $request->customLabelText = 'Shipping comment...';

        return $request;
    }

    /**
     * Creates the DPD extra insurance object.
     *
     * @param Shipment\ShipmentDataInterface $shipment
     *
     * @return Dpd\EPrint\Model\ExtraInsurance
     */
    protected function createExtraInsurance(Shipment\ShipmentDataInterface $shipment)
    {
        $value = $shipment->getValorization();
        if (0 >= $value) {
            if ($shipment instanceof Shipment\ShipmentInterface) {
                $value = $this->calculateGoodsValue($shipment);
            } else if ($shipment instanceof Shipment\ShipmentParcelInterface) {
                throw new ShipmentGatewayException("Parcel's valorization must be set.");
            } else {
                throw new InvalidArgumentException("Expected shipment or parcel");
            }
        }

        $insurance = new Dpd\EPrint\Model\ExtraInsurance();

        $insurance->type = Dpd\EPrint\Enum\ETypeInsurance::BY_SHIPMENTS;
        $insurance->value = (string)round($value, 2);

        return $insurance;
    }

    /**
     * Creates the multi shipment request.
     *
     * @param Shipment\ShipmentInterface $shipment
     *
     * @return Dpd\EPrint\Request\MultiShipmentRequest
     */
    protected function createMultiShipmentRequest(Shipment\ShipmentInterface $shipment)
    {
        if (!$shipment->hasParcels()) {
            throw new InvalidArgumentException("Expected shipment with parcels.");
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

        $data = $shipment->getGatewayData();

        $addInsurance = isset($data['insurance']) && $data['insurance'];

        $index = 0;
        foreach ($shipment->getParcels() as $parcel) {
            $slave = new Dpd\EPrint\Model\SlaveRequest();
            $slave->weight = round($parcel->getWeight(), 2); // kg
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
     *
     * @param AddressInterface         $address
     * @param Dpd\EPrint\Model\Address $target
     *
     * @return Dpd\EPrint\Model\Address
     */
    protected function createAddress(AddressInterface $address, Dpd\EPrint\Model\Address $target = null)
    {
        if (null === $target) {
            $target = new Dpd\EPrint\Model\Address();
        }

        if ($address->getFirstName() && $address->getLastName()) {
            $target->name = $address->getFirstName() . ' ' . $address->getLastName();
        } elseif ($address->getCompany()) {
            $target->name = $address->getCompany();
        }

        $target->countryPrefix = $address->getCountry()->getCode();
        $target->zipCode = $address->getPostalCode();
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
     *
     * @param AddressInterface $address
     *
     * @return Dpd\EPrint\Model\AddressInfo|null
     */
    protected function createAddressInfo(AddressInterface $address)
    {
        $info = new Dpd\EPrint\Model\AddressInfo();
        $empty = true;

        if ($address->getFirstName() && $address->getLastName() && $address->getCompany()) {
            $info->name2 = $address->getCompany();
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
     *
     * @return Dpd\EPrint\Model\Customer
     */
    protected function createCustomer()
    {
        $customer = new Dpd\EPrint\Model\Customer();

        $customer->number = $this->config['customer_number'];
        $customer->countrycode = $this->config['country_code'];
        $customer->centernumber = $this->config['center_number'];

        return $customer;
    }

    /**
     * Creates EPrint parcel from the given component shipment data.
     *
     * @param Shipment\ShipmentDataInterface $shipment
     *
     * @return Dpd\EPrint\Model\Parcel
     */
    protected function createParcel(Shipment\ShipmentDataInterface $shipment)
    {
        if (empty($shipment->getTrackingNumber())) {
            throw new RuntimeException("Shipment (or parcel) must have its tracking number.");
        }

        $parcel = new Dpd\EPrint\Model\Parcel();

        $parcel->parcelnumber = $shipment->getTrackingNumber();
        $parcel->countrycode = $this->config['country_code'];
        $parcel->centernumber = $this->config['center_number'];

        return $parcel;
    }

    /**
     * Creates a EPrint label type.
     *
     * @return Dpd\EPrint\Model\LabelType
     */
    protected function createLabelType()
    {
        $labelType = new Dpd\EPrint\Model\LabelType();

        $labelType->type = Dpd\EPrint\Enum\ELabelType::PNG;

        return $labelType;
    }

    /**
     * Converts the DPD label type to the commerce label type.
     *
     * @param string $type
     *
     * @return string
     */
    protected function convertLabelType(string $type)
    {
        switch ($type) {
            case Dpd\EPrint\Enum\EType::REVERSE:
                return OrderShipmentLabel::TYPE_RETURN;
            case Dpd\EPrint\Enum\EType::PROOF:
                return OrderShipmentLabel::TYPE_PROOF;
            case Dpd\EPrint\Enum\EType::EPRINT_ATTACHMENT:
                return OrderShipmentLabel::TYPE_SUMMARY;
        }

        return OrderShipmentLabel::TYPE_SHIPMENT;
    }

    /**
     * Returns the EPrint api.
     *
     * @return Dpd\EPrint\Api
     */
    protected function getEPrintApi()
    {
        if (null !== $this->ePrintApi) {
            return $this->ePrintApi;
        }

        return $this->ePrintApi = new Dpd\EPrint\Api([
            'login'    => $this->config['eprint']['login'],
            'password' => $this->config['eprint']['password'],
            'cache'    => $this->config['cache'],
            'debug'    => $this->config['debug'],
            'test'     => $this->config['test'],
        ]);
    }

    /**
     * Formats the phone number.
     *
     * @param mixed $number
     *
     * @return string
     */
    protected function formatPhoneNumber($number)
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