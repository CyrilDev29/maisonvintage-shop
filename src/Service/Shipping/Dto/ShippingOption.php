<?php

declare(strict_types=1);

namespace App\Service\Shipping\Dto;

use App\Service\Shipping\Model\Carrier;

/**
 * Offre d’expédition affichable au client.
 * amountCents = montant TTC en centimes.
 */
final class ShippingOption
{
    public function __construct(
        public readonly Carrier $carrier,
        public readonly string $code,         // ex: "DOMICILE", "RELAIS"
        public readonly string $label,        // ex: "Colissimo — Domicile"
        public readonly int $amountCents,     // TTC en centimes
        public readonly ?array $metadata = [] // ex: relayId, délai…
    ) {}
}
