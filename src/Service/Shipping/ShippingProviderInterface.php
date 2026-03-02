<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Entity\Address;
use App\Service\Shipping\Dto\ShippingQuote;

/**
 * Contrat minimal pour un provider d'expédition.
 */
interface ShippingProviderInterface
{
    /** Code transporteur (ex: 'COLISSIMO'). */
    public function getCode(): string;

    /** Nom lisible (ex: 'Colissimo'). */
    public function getName(): string;

    /**
     * Calcule un devis d’expédition pour un panier + une adresse.
     *
     * @param array<int,array{qty:int,weight_kg?:float,weight_gr?:int|null}> $cartLines
     */
    public function quote(array $cartLines, Address $shippingAddress): ShippingQuote;

    /** URL publique de suivi si dispo (sinon null). */
    public function trackingUrl(?string $trackingNumber): ?string;
}
