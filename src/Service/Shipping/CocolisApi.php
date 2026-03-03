<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Service\Shipping\Dto\ShippingOption;
use App\Service\Shipping\Dto\ShippingQuote;
use App\Service\Shipping\Model\Carrier;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API Cocolis pour transport de gros objets ou meubles.
 */
final class CocolisApi
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Retourne une quote Cocolis DOMICILE (centimes) selon poids & dimensions.
     * Fallback local si API désactivée ou en erreur.
     *
     * @param int $totalWeightGr poids total en grammes
     * @param string $countryCode code pays ISO2
     */
    public function quoteDomicile(int $totalWeightGr, string $countryCode = 'FR'): ShippingQuote
    {
        // Fallback si API off
        if (!$this->enabled) {
            return $this->fallbackQuote($totalWeightGr, $countryCode);
        }

        try {
            // TODO: Intégration réelle API Cocolis
            // Pour l'instant, on utilise le fallback
            return $this->fallbackQuote($totalWeightGr, $countryCode);

            // Exemple d'appel API (à adapter selon la vraie API Cocolis)
            /*
            $resp = $this->http->request('POST', 'https://api.cocolis.fr/rates', [
                'json' => [
                    'apiKey'  => $this->apiKey,
                    'country' => strtoupper($countryCode),
                    'weight'  => max(1, $totalWeightGr),
                ],
                'timeout' => 6.0,
            ]);

            if (200 !== $resp->getStatusCode()) {
                return $this->fallbackQuote($totalWeightGr, $countryCode);
            }

            $data = $resp->toArray(false);
            $cents = (int) ($data['amount_cents'] ?? 0);

            if ($cents <= 0) {
                return $this->fallbackQuote($totalWeightGr, $countryCode);
            }

            $opt = new ShippingOption(
                Carrier::COCOLIS,
                'DOMICILE',
                'Cocolis — Livraison à domicile',
                $cents
            );

            return new ShippingQuote([$opt]);
            */
        } catch (\Throwable) {
            return $this->fallbackQuote($totalWeightGr, $countryCode);
        }
    }

    /**
     * Barème fallback pour gros objets (tarifs indicatifs plus élevés).
     */
    private function fallbackQuote(int $totalWeightGr, string $countryCode): ShippingQuote
    {
        $isFR = strtoupper($countryCode) === 'FR';
        $w = max(1, $totalWeightGr);

        // Cocolis : pour gros objets, tarifs plus élevés
        if ($isFR) {
            // France : 0-5kg: 19.90€, 5-10kg: 29.90€, 10-20kg: 39.90€, >20kg: 59.90€
            $cents =
                ($w <= 5000)  ? 1990 :
                    (($w <= 10000) ? 2990 :
                        (($w <= 20000) ? 3990 : 5990));
        } else {
            // Europe : tarifs majorés
            $cents =
                ($w <= 5000)  ? 2990 :
                    (($w <= 10000) ? 3990 :
                        (($w <= 20000) ? 4990 : 6990));
        }

        $opt = new ShippingOption(
            Carrier::COCOLIS,
            'DOMICILE',
            'Cocolis — Livraison à domicile (gros objets)',
            $cents,
            ['source' => 'fallback']
        );

        return new ShippingQuote([$opt]);
    }
}
