<?php

declare(strict_types=1);

namespace Ekyna\Bundle\DpdBundle\Platform;

use Ekyna\Component\Commerce\Exception\InvalidArgumentException;

/**
 * Class Service
 * @package Ekyna\Bundle\DpdBundle\Platform
 * @author  Etienne Dauvergne <contact@ekyna.com>
 */
final class Service
{
    public const CLASSIC      = 'Classic';
    public const PREDICT      = 'Predict';
    public const RELAY        = 'Relay';
    public const RETURN       = 'Return';
    public const RELAY_RETURN = 'RelayReturn';


    /**
     * Returns the available services codes.
     *
     * @return array<string>
     */
    public static function getCodes(): array
    {
        return [
            self::CLASSIC,
            self::PREDICT,
            self::RELAY,
            self::RETURN,
            self::RELAY_RETURN,
        ];
    }

    /**
     * Returns whether the given code is valid.
     *
     * @param string $code
     * @param bool   $throw
     *
     * @return bool
     */
    public static function isValid(string $code, bool $throw = true): bool
    {
        if (in_array($code, self::getCodes())) {
            return true;
        }

        if ($throw) {
            throw new InvalidArgumentException('Unexpected DPD service code.');
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
    public static function getLabel(string $code): string
    {
        self::isValid($code);

        return match ($code) {
            self::RELAY        => 'DPD Relais',
            self::PREDICT      => 'DPD Predict',
            self::RETURN       => 'DPD Retour',
            self::RELAY_RETURN => 'DPD Retour par relais',
            default            => 'DPD Classic',
        };
    }

    /**
     * Returns the choices.
     *
     * @return array
     */
    public static function getChoices(): array
    {
        $choices = [];

        foreach (self::getCodes() as $code) {
            $choices[self::getLabel($code)] = $code;
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
