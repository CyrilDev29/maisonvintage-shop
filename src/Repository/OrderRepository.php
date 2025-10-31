<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * @return Order[]
     */
    public function findByUserOrdered(User $user): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.user = :u')->setParameter('u', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Commandes en attente dont la réservation a expiré.
     * Utilisé par la future commande CRON de libération automatique.
     *
     * @return Order[]
     */
    public function findExpiredPending(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.status = :status')
            ->andWhere('o.reservedUntil IS NOT NULL')
            ->andWhere('o.reservedUntil <= :now')
            ->setParameter('status', OrderStatus::EN_ATTENTE_PAIEMENT)
            ->setParameter('now', $now)
            ->orderBy('o.reservedUntil', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
