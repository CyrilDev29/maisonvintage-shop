<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Entity\Address;
use App\Service\Shipping\Dto\ShippingOption;
use App\Service\Shipping\Dto\ShippingQuote;
use App\Service\Shipping\Model\Carrier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fournisseur Colissimo (La Poste).
 * - Essaie l’API (SimTao / devis) si configurée ; sinon fallback local.
 */
final class ColissimoProvider implements ShippingProviderInterface
{
    private string $simtaoBase = '/';
    private string $simtaoKey  = '';
    private bool   $live       = false;

    public function __construct(
        private readonly HttpClientInterface   $http,
        private readonly ParameterBagInterface $params,
        private readonly ?LoggerInterface      $logger = null,
    ) {
        // On lit les *parameters* Symfony (mappés depuis .env dans services.yaml)
        $base = (string) ($this->params->has('colissimo.api_base') ? ($this->params->get('colissimo.api_base') ?? '') : '');
        $key  = (string) ($this->params->has('colissimo.api_key')  ? ($this->params->get('colissimo.api_key')  ?? '') : '');
        $live = (bool)   ($this->params->has('shipping.colissimo_live') ? ($this->params->get('shipping.colissimo_live') ?? false) : false);

        $this->simtaoBase = rtrim($base, '/') . '/';
        $this->simtaoKey  = $key;
        $this->live       = $live;
    }

    public function getCode(): string   { return Carrier::COLISSIMO->value; }
    public function getName(): string   { return 'Colissimo'; }

    /**
     * @param array<int,array{qty:int,weight_kg?:float,weight_gr?:int|null}> $cartLines
     */
    public function quote(array $cartLines, Address $shippingAddress): ShippingQuote
    {
        // 1) Poids total (kg)
        $totalItems = 0;
        $weightKg   = 0.0;

        foreach ($cartLines as $line) {
            $qty = max(0, (int) ($line['qty'] ?? 0));
            $wkg = isset($line['weight_kg']) ? (float) $line['weight_kg'] : 0.0;
            $wgr = isset($line['weight_gr']) ? (int) $line['weight_gr'] : 0;
            $w   = $wkg > 0 ? $wkg : ($wgr > 0 ? ($wgr / 1000) : 0.8);

            $totalItems += $qty;
            $weightKg   += $w * $qty;
        }
        if ($weightKg <= 0 && $totalItems > 0) {
            $weightKg = 0.8 * $totalItems;
        }

        // 2) Pays / CP
        $country  = strtoupper((string) ($shippingAddress->getCountry() ?? 'FR'));
        $postcode = (string) ($shippingAddress->getPostalCode() ?? '');

        // 3) Tente l’API si dispo
        $options = [];
        if ($this->canCallSimTao()) {
            try {
                $apiOptions = $this->callSimTao($weightKg, $country, $postcode);
                $options    = $this->mapSimTaoToOptions($apiOptions);
            } catch (\Throwable $e) {
                $this->log('warning', 'Colissimo SimTao error; fallback activé', ['error' => $e->getMessage()]);
            }
        }

        // 4) Fallback si aucune offre API
        if (!$options) {
            $options = $this->fallbackOptions($weightKg, $country);
        }

        return new ShippingQuote($options);
    }

    public function trackingUrl(?string $trackingNumber): ?string
    {
        if (!$trackingNumber) return null;
        return sprintf('https://www.laposte.fr/outils/suivre-vos-envois?code=%s', urlencode($trackingNumber));
    }

    // ----------- Internes -----------

    private function canCallSimTao(): bool
    {
        return $this->simtaoBase !== '/' && $this->simtaoKey !== '';
    }

    /** @return array<mixed> */
    private function callSimTao(float $weightKg, string $country, string $postalCode): array
    {
        $url = $this->simtaoBase . 'rates';

        $resp = $this->http->request('GET', $url, [
            'headers' => [
                'Accept'    => 'application/json',
                'X-Api-Key' => $this->simtaoKey,
            ],
            'query'   => [
                'weight_kg'  => max(0.01, $weightKg),
                'country'    => $country,
                'postalcode' => $postalCode,
                'env'        => $this->live ? 'prod' : 'sandbox',
            ],
            'timeout' => 8,
        ]);

        if (200 !== $resp->getStatusCode()) {
            throw new \RuntimeException('Erreur SimTao : HTTP ' . $resp->getStatusCode());
        }
        $data = $resp->toArray(false);
        if (!\is_array($data)) {
            throw new \RuntimeException('Réponse SimTao inattendue');
        }
        return $data;
    }

    /** @return ShippingOption[] */
    private function mapSimTaoToOptions(array $data): array
    {
        $out = [];
        $offers = $data['offers'] ?? $data['data'] ?? $data;

        if (!\is_iterable($offers)) return $out;

        foreach ($offers as $raw) {
            if (!\is_array($raw)) continue;

            $code  = (string) ($raw['code'] ?? 'HOME');
            $label = (string) ($raw['label'] ?? 'Colissimo');
            $cents = (int) ($raw['amount_cents'] ?? ($raw['price_cents'] ?? 0));
            if ($cents <= 0) continue;

            $out[] = new ShippingOption(
                carrier: Carrier::COLISSIMO,
                code: $code,
                label: $label,
                amountCents: $cents,
                metadata: [
                    'raw'  => $raw,
                    'type' => (string) ($raw['type'] ?? ''),
                ]
            );
        }
        return $out;
    }

    /** @return ShippingOption[] */
    private function fallbackOptions(float $weightKg, string $country): array
    {
        $tiers = [
            0.5 => 4.90,
            1.0 => 6.90,
            5.0 => 9.90,
            PHP_FLOAT_MAX => 14.90,
        ];

        $base = 14.90;
        foreach ($tiers as $max => $price) {
            if ($weightKg <= $max) { $base = $price; break; }
        }

        if ($country !== 'FR') {
            $eu = ['BE','LU','NL','DE','IT','ES','PT','IE','AT','PL','CZ','DK','SE','FI','HU','RO','BG','HR','SI','SK','EE','LV','LT','GR','CY','MT'];
            $base += \in_array($country, $eu, true) ? 3.00 : 8.00;
        }

        $home   = (int) round($base * 100);
        $relay  = (int) round(($base - 1.00) * 100);
        $pickup = (int) round(($base + 1.00) * 100);

        return [
            new ShippingOption(Carrier::COLISSIMO, 'HOME',  'Colissimo - Livraison à domicile', $home,   ['fallback' => true]),
            new ShippingOption(Carrier::COLISSIMO, 'RELAY', 'Colissimo - Point relais',         max(0,$relay), ['fallback' => true, 'needs_relay' => true]),
            new ShippingOption(Carrier::COLISSIMO, 'PICKUP','Colissimo - Retrait bureau de poste', $pickup, ['fallback' => true]),
        ];
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) $this->logger->log($level, $message, $context);
    }
}
