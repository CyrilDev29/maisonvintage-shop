<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Service\Shipping\Dto\ShippingOption;
use App\Service\Shipping\Dto\ShippingQuote;
use App\Service\Shipping\Model\Carrier;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API Mondial Relay pour calculer les tarifs et trouver des points relais.
 */
final class MondialRelayApi
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Retourne une quote Mondial Relay POINT_RELAIS (centimes) selon poids & pays.
     * Fallback local si API désactivée ou en erreur.
     *
     * @param int $totalWeightGr poids total du panier en grammes
     * @param string $countryCode code pays ISO2 (ex: FR, BE, ES)
     */
    public function quotePointRelais(int $totalWeightGr, string $countryCode = 'FR'): ShippingQuote
    {
        // Fallback (barème simple) si API off
        if (!$this->enabled) {
            return $this->fallbackQuote($totalWeightGr, $countryCode);
        }

        try {
            // TODO: Intégration réelle API Mondial Relay
            // Pour l'instant, on utilise le fallback
            return $this->fallbackQuote($totalWeightGr, $countryCode);

            // Exemple d'appel API (à adapter selon la vraie API Mondial Relay)
            /*
            $resp = $this->http->request('POST', 'https://api.mondialrelay.com/rates', [
                'json' => [
                    'apiKey'    => $this->apiKey,
                    'apiSecret' => $this->apiSecret,
                    'country'   => strtoupper($countryCode),
                    'weight'    => max(1, $totalWeightGr),
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
                Carrier::MONDIAL_RELAY,
                'POINT_RELAIS',
                'Mondial Relay — Point Relais',
                $cents,
                ['requires_relay' => true]
            );

            return new ShippingQuote([$opt]);
            */
        } catch (\Throwable) {
            return $this->fallbackQuote($totalWeightGr, $countryCode);
        }
    }

    /**
     * Barème fallback (tarifs indicatifs).
     */
    private function fallbackQuote(int $totalWeightGr, string $countryCode): ShippingQuote
    {
        $isFR = strtoupper($countryCode) === 'FR';
        $w = max(1, $totalWeightGr);

        if ($isFR) {
            // France : 0-500g: 4.90€, 500-1000g: 5.90€, 1000-2000g: 6.90€, >2kg: 8.90€
            $cents =
                ($w <= 500)  ? 490 :
                    (($w <= 1000) ? 590 :
                        (($w <= 2000) ? 690 : 890));
        } else {
            // Europe : tarifs légèrement plus élevés
            $cents =
                ($w <= 500)  ? 690 :
                    (($w <= 1000) ? 890 :
                        (($w <= 2000) ? 990 : 1290));
        }

        $opt = new ShippingOption(
            Carrier::MONDIAL_RELAY,
            'POINT_RELAIS',
            'Mondial Relay — Point Relais',
            $cents,
            [
                'source' => 'fallback',
                'requires_relay' => true
            ]
        );

        return new ShippingQuote([$opt]);
    }
}
