<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Service\Shipping\Dto\ShippingOption;
use App\Service\Shipping\Dto\ShippingQuote;
use App\Service\Shipping\Model\Carrier;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ColissimoApi
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiBase,
        private readonly string $apiKey,
        private readonly bool $enabled = true,
    ) {}

    /**
     * Retourne une quote Colissimo DOMICILE (centimes) selon poids & pays.
     * Fallback local si API désactivée ou en erreur.
     *
     * @param int $totalWeightGr poids total du panier en grammes
     * @param string $countryCode code pays ISO2 (ex: FR, BE, ES)
     */
    public function quoteDomicile(int $totalWeightGr, string $countryCode = 'FR'): ShippingQuote
    {
        // Fallback (barème simple) si API off
        if (!$this->enabled) {
            return $this->fallbackQuote($totalWeightGr, $countryCode);
        }

        try {
            // Exemple d’endpoint — adapte à ton vrai fournisseur
            $resp = $this->http->request('POST', 'rates/colissimo/domicile', [
                'json' => [
                    'country' => strtoupper($countryCode),
                    'weight_grams' => max(1, $totalWeightGr),
                ],
                'timeout' => 6.0,
            ]);

            if (200 !== $resp->getStatusCode()) {
                return $this->fallbackQuote($totalWeightGr, $countryCode);
            }

            $data = $resp->toArray(false);
            // on suppose un payload du type { "amount_cents": 790, "service": "DOMICILE" }
            $cents = (int) ($data['amount_cents'] ?? 0);
            if ($cents <= 0) {
                return $this->fallbackQuote($totalWeightGr, $countryCode);
            }

            $opt = new ShippingOption(
                Carrier::COLISSIMO,
                'DOMICILE',
                'Colissimo — Livraison à domicile',
                $cents
            );

            return new ShippingQuote([$opt]);
        } catch (\Throwable) {
            return $this->fallbackQuote($totalWeightGr, $countryCode);
        }
    }

    private function fallbackQuote(int $totalWeightGr, string $countryCode): ShippingQuote
    {
        // Barème très simple : FR vs hors FR + paliers de poids
        $isFR = strtoupper($countryCode) === 'FR';
        $w = max(1, $totalWeightGr);

        if ($isFR) {
            // ex: 0–500g: 5.90€, 500–1000g: 7.90€, 1000–2000g: 9.90€, >2kg: 12.90€
            $cents =
                ($w <= 500)  ? 590 :
                    (($w <= 1000) ? 790 :
                        (($w <= 2000) ? 990 : 1290));
        } else {
            // UE simple: +3€
            $cents =
                ($w <= 500)  ? 890 :
                    (($w <= 1000) ? 1090 :
                        (($w <= 2000) ? 1290 : 1590));
        }

        $opt = new ShippingOption(
            Carrier::COLISSIMO,
            'DOMICILE',
            'Colissimo — Livraison à domicile',
            $cents,
            ['source' => 'fallback']
        );

        return new ShippingQuote([$opt]);
    }
}
