<?php

declare(strict_types=1);

namespace App\Service\Shipping\Model;

/**
 * Enum des transporteurs supportés.
 */
enum Carrier: string
{
    case COLISSIMO      = 'COLISSIMO';
    case MONDIAL_RELAY  = 'MONDIAL_RELAY';
    case COCOLIS        = 'COCOLIS';

    /**
     * Retourne le label affiché côté utilisateur.
     */
    public function label(): string
    {
        return match($this) {
            self::COLISSIMO     => 'Colissimo (La Poste)',
            self::MONDIAL_RELAY => 'Mondial Relay',
            self::COCOLIS       => 'Cocolis',
        };
    }

    /**
     * Retourne true si le transporteur nécessite un point relais.
     */
    public function requiresRelay(): bool
    {
        return match($this) {
            self::MONDIAL_RELAY => true,
            self::COLISSIMO     => false,
            self::COCOLIS       => false,
        };
    }
}
