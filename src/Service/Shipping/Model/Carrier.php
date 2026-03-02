<?php

declare(strict_types=1);

namespace App\Service\Shipping\Model;

enum Carrier: string
{
    case COLISSIMO     = 'COLISSIMO';
    case MONDIAL_RELAY = 'MONDIAL_RELAY';
    case COCOLIS       = 'COCOLIS';
}
