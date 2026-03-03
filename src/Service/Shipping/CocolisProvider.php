<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Entity\Address;
use App\Service\Shipping\Dto\ShippingQuote;

/**
 * Provider Cocolis : transport de gros objets ou meubles.
 */
final class CocolisProvider implements ShippingProviderInterface
{
    public function __construct(
        private readonly CocolisApi $api,
    ) {}

    public function getCode(): string
    {
        return 'COCOLIS';
    }

    public function getName(): string
    {
        return 'Cocolis';
    }

    /**
     * Calcule le tarif Cocolis pour gros objets.
     *
     * @param array<int, array{qty: int, weight_kg?: float, weight_gr?: int|null}> $cartLines
     */
    public function quote(array $cartLines, Address $shippingAddress): ShippingQuote
    {
        // Calcul du poids total en grammes
        $totalWeightGr = 0;
        foreach ($cartLines as $line) {
            $qty = max(1, (int) ($line['qty'] ?? 1));

            // Priorité : weight_gr puis weight_kg
            if (isset($line['weight_gr']) && $line['weight_gr'] > 0) {
                $totalWeightGr += $qty * (int) $line['weight_gr'];
            } elseif (isset($line['weight_kg']) && $line['weight_kg'] > 0) {
                $totalWeightGr += $qty * (int) round($line['weight_kg'] * 1000);
            }
        }

        // Si aucun poids défini, on estime 2kg par article (objets plus lourds)
        if ($totalWeightGr === 0) {
            $totalItems = array_sum(array_column($cartLines, 'qty'));
            $totalWeightGr = max(1, $totalItems) * 2000; // 2kg par défaut
        }

        $countryCode = strtoupper($shippingAddress->getCountry() ?? 'FR');

        // Appel API
        return $this->api->quoteDomicile($totalWeightGr, $countryCode);
    }

    public function trackingUrl(?string $trackingNumber): ?string
    {
        if (!$trackingNumber) {
            return null;
        }

        // URL de suivi Cocolis (à adapter selon leur vrai système)
        return sprintf('https://www.cocolis.fr/suivi/%s', urlencode($trackingNumber));
    }
}
