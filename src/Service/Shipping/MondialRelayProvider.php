<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Entity\Address;
use App\Service\Shipping\Dto\ShippingQuote;

/**
 * Provider Mondial Relay : calcule les tarifs et gère les points relais.
 */
final class MondialRelayProvider implements ShippingProviderInterface
{
    public function __construct(
        private readonly MondialRelayApi $api,
    ) {}

    public function getCode(): string
    {
        return 'MONDIAL_RELAY';
    }

    public function getName(): string
    {
        return 'Mondial Relay';
    }

    /**
     * Calcule le tarif Mondial Relay Point Relais.
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

        // Si aucun poids défini, on estime 500g par article
        if ($totalWeightGr === 0) {
            $totalItems = array_sum(array_column($cartLines, 'qty'));
            $totalWeightGr = max(1, $totalItems) * 500;
        }

        $countryCode = strtoupper($shippingAddress->getCountry() ?? 'FR');

        // Appel API
        return $this->api->quotePointRelais($totalWeightGr, $countryCode);
    }

    public function trackingUrl(?string $trackingNumber): ?string
    {
        if (!$trackingNumber) {
            return null;
        }

        // URL de suivi Mondial Relay
        return sprintf('https://www.mondialrelay.fr/suivi-de-colis/?numeroExpedition=%s', urlencode($trackingNumber));
    }
}
