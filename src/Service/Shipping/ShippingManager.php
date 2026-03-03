<?php

declare(strict_types=1);

namespace App\Service\Shipping;

use App\Entity\Address;
use App\Service\Shipping\Dto\ShippingQuote;

/**
 * Agrège les providers et renvoie une liste d'options de tous les transporteurs.
 */
final class ShippingManager
{
    /**
     * @var array<ShippingProviderInterface>
     */
    private readonly array $providers;

    public function __construct(
        private readonly ColissimoProvider $colissimo,
        private readonly MondialRelayProvider $mondialRelay,
        private readonly CocolisProvider $cocolis,
    ) {
        // Liste de tous les providers disponibles
        $this->providers = [
            $this->colissimo,
            $this->mondialRelay,
            $this->cocolis,
        ];
    }

    /**
     * Retourne les options de tous les transporteurs disponibles.
     *
     * @param array<int,array{qty:int,weight_gr?:int|null,weight_kg?:float|null}> $cartLines
     */
    public function quoteAll(array $cartLines, Address $address): ShippingQuote
    {
        $allOptions = [];

        foreach ($this->providers as $provider) {
            try {
                // Récupère les options de chaque provider
                $quote = $provider->quote($cartLines, $address);

                // Ajoute toutes les options de ce provider
                foreach ($quote->options as $option) {
                    $allOptions[] = $option;
                }
            } catch (\Throwable $e) {
                // Si un provider échoue, on continue avec les autres
                // En prod, tu pourrais logger l'erreur
                continue;
            }
        }

        return new ShippingQuote($allOptions);
    }

    /**
     * Retourne les options Colissimo uniquement.
     *
     * @param array<int,array{qty:int,weight_gr?:int|null,weight_kg?:float|null}> $cartLines
     */
    public function quoteColissimo(array $cartLines, Address $address): ShippingQuote
    {
        return $this->colissimo->quote($cartLines, $address);
    }

    /**
     * Retourne les options Mondial Relay uniquement.
     *
     * @param array<int,array{qty:int,weight_gr?:int|null,weight_kg?:float|null}> $cartLines
     */
    public function quoteMondialRelay(array $cartLines, Address $address): ShippingQuote
    {
        return $this->mondialRelay->quote($cartLines, $address);
    }

    /**
     * Retourne les options Cocolis uniquement.
     *
     * @param array<int,array{qty:int,weight_gr?:int|null,weight_kg?:float|null}> $cartLines
     */
    public function quoteCocolis(array $cartLines, Address $address): ShippingQuote
    {
        return $this->cocolis->quote($cartLines, $address);
    }

    /**
     * Retourne un provider spécifique par son code.
     */
    public function getProvider(string $code): ?ShippingProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->getCode() === $code) {
                return $provider;
            }
        }

        return null;
    }
}
