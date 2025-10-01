<?php

namespace App\Enum;

enum OrderStatus: string
{
    case EN_COURS = 'En cours';
    case EN_PREPARATION = 'En préparation';
    case EXPEDIEE = 'Expédiée';
    case ANNULEE = 'Annulée';
    case LIVREE = 'Livrée'; // ← nouveau

    /** Labels réutilisables dans des ChoiceType */
    public static function choices(): array
    {
        return [
            self::EN_COURS->value        => self::EN_COURS,
            self::EN_PREPARATION->value  => self::EN_PREPARATION,
            self::EXPEDIEE->value        => self::EXPEDIEE,
            self::ANNULEE->value         => self::ANNULEE,
            self::LIVREE->value          => self::LIVREE,
        ];
    }
}
