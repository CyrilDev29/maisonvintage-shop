<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Service\Shipping\Dto\ShippingOption;
use App\Service\Shipping\Dto\ShippingQuote;
use App\Service\Shipping\Model\Carrier;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CocolisApi
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly bool $enabled = true,
    ) {}

    public function quoteDomicile(int $totalWeightGr, string $countryCode = 'FR'): ShippingQuote
    {
        return $this->fallbackQuote($totalWeightGr, $countryCode);
    }

    private function fallbackQuote(int $totalWeightGr, string $countryCode): ShippingQuote
    {
        $opt = new ShippingOption(
            Carrier::COCOLIS,
            'DOMICILE',
            'Cocolis — Gros objets (meubles, luminaires, miroirs)',
            7000,
            ['source' => 'fallback']
        );

        return new ShippingQuote([$opt]);
    }
}
