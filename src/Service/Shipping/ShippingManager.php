<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Entity\Address;
use App\Service\Shipping\Dto\ShippingOption;
use App\Service\Shipping\Dto\ShippingQuote;
use App\Service\Shipping\Model\Carrier;

/**
 * Agrège les providers et renvoie une liste d’options.
 */
final class ShippingManager
{
    public function __construct(
        private readonly ColissimoProvider $colissimo,
    ) {}

    /**
     * @param array<int,array{qty:int,weight_gr?:int|null,weight_kg?:float|null}> $cartLines
     */
    public function quoteColissimo(array $cartLines, Address $address): ShippingQuote
    {
        return $this->colissimo->quote($cartLines, $address);
    }

    /** Mondial Relay : stub */
    public function quoteMondialRelay(array $cartLines, Address $address): ShippingQuote
    {
        $opts = [
            new ShippingOption(Carrier::MONDIAL_RELAY, 'RELAIS',   'Mondial Relay — Point relais', 499, ['needRelaySelection' => true]),
            new ShippingOption(Carrier::MONDIAL_RELAY, 'DOMICILE', 'Mondial Relay — Domicile',     899),
        ];
        return new ShippingQuote($opts);
    }

    /** Cocolis : stub */
    public function quoteCocolis(array $cartLines, Address $address): ShippingQuote
    {
        $opts = [
            new ShippingOption(Carrier::COCOLIS, 'ECO', 'Cocolis — Partage de trajet (éco)', 1299, ['estimation' => 'variable']),
        ];
        return new ShippingQuote($opts);
    }

    /** Toutes les options (confort) */
    public function quoteAll(array $cartLines, Address $address): ShippingQuote
    {
        $all = [];
        foreach ([$this->quoteColissimo($cartLines, $address),
                     $this->quoteMondialRelay($cartLines, $address),
                     $this->quoteCocolis($cartLines, $address)] as $q) {
            foreach ($q->options as $o) { $all[] = $o; }
        }
        return new ShippingQuote($all);
    }
}
