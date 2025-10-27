<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * États possibles d'une commande dans le cycle de traitement.
 * Compatible avec les paiements Stripe et le back-office EasyAdmin.
 */
enum OrderStatus: string
{
    // Commande créée mais pas encore réglée
    case EN_ATTENTE_PAIEMENT = 'En attente de paiement';

    // Paiement validé, commande en cours de traitement
    case EN_COURS = 'En cours';

    // Commande en préparation (optionnel : étape intermédiaire)
    case EN_PREPARATION = 'En préparation';

    // Commande expédiée
    case EXPEDIEE = 'Expédiée';

    // Commande livrée
    case LIVREE = 'Livrée';

    // Commande annulée (par client ou admin)
    case ANNULEE = 'Annulée';

    // Paiement échoué (carte refusée, solde insuffisant, etc.)
    case ECHEC = 'Échec';

    /**
     * Liste des statuts utilisables dans un ChoiceType
     * ou un menu déroulant EasyAdmin.
     *
     * @return array<string, OrderStatus>
     */
    public static function choices(): array
    {
        return [
            self::EN_ATTENTE_PAIEMENT->value => self::EN_ATTENTE_PAIEMENT,
            self::EN_COURS->value            => self::EN_COURS,
            self::EN_PREPARATION->value      => self::EN_PREPARATION,
            self::EXPEDIEE->value            => self::EXPEDIEE,
            self::LIVREE->value              => self::LIVREE,
            self::ANNULEE->value             => self::ANNULEE,
            self::ECHEC->value               => self::ECHEC,
        ];
    }
}
