<?php

namespace Ekyna\Bundle\DpdBundle\Platform;

use Ekyna\Component\Commerce\Exception\InvalidArgumentException;

/**
 * Class Service
 * @package Ekyna\Bundle\DpdBundle\Platform
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
abstract class Service
{
    const CLASSIC      = 'Classic';
    const PREDICT      = 'Predict';
    const RELAY        = 'Relay';
    const RETURN       = 'Return';
    const RELAY_RETURN = 'RelayReturn';


    /**
     * Returns the available services codes.
     *
     * @return array|string[]
     */
    static public function getCodes()
    {
        return [
            static::CLASSIC,
            static::PREDICT,
            static::RELAY,
            static::RETURN,
            static::RELAY_RETURN,
        ];
    }

    /**
     * Returns whether or not the given code is valid.
     *
     * @param string $code
     * @param bool   $throw
     *
     * @return bool
     */
    static public function isValid($code, $throw = true)
    {
        if (in_array($code, static::getCodes())) {
            return true;
        }

        if ($throw) {
            throw new InvalidArgumentException("Unexpected DPD service code.");
        }

        return false;
    }

    /**
     * Returns the label for the given product code.
     *
     * @param string $code
     *
     * @return string
     */
    static public function getLabel($code)
    {
        static::isValid($code);

        switch ($code) {
            case static::RELAY:
                return 'DPD Relais';
            case static::PREDICT:
                return 'DPD Predict';
            case static::RETURN:
                return 'DPD Retour';
            case static::RELAY_RETURN:
                return 'DPD Retour par relais';
            default:
                return 'DPD Classic';
        }
    }

    /**
     * Returns the choices.
     *
     * @return array
     */
    static public function getChoices()
    {
        $choices = [];

        foreach (static::getCodes() as $code) {
            $choices[static::getLabel($code)] = $code;
        }

        return $choices;
    }

    /**
     * Disabled constructor.
     */
    private function __construct()
    {
    }
}
