<?php

namespace App\Enum;

/**
 * Transporteurs supportés par l’application.
 * Étendable sans impact rétro (on ajoutera Mondial Relay et Cocolis ensuite).
 */
enum ShippingCarrier: string
{
    case COLISSIMO = 'COLISSIMO';
    case MONDIAL_RELAY = 'MONDIAL_RELAY';
    case COCOLIS = 'COCOLIS';
}
