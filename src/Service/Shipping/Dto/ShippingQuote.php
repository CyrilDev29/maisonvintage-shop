<?php

declare(strict_types=1);

namespace App\Service\Shipping\Dto;

final class ShippingQuote
{
    /** @param array<int,ShippingOption> $options */
    public function __construct(
        public readonly array $options
    ) {}
}
