<?php

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Categorie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    /**
     * Retourne les articles disponibles (> 0) pour une catégorie donnée.
     */
    public function findDisponiblesByCategorie(Categorie $categorie): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.categorie = :c')
            ->andWhere('a.quantity > 0')
            ->setParameter('c', $categorie)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne les articles disponibles (> 0) pour un slug de catégorie.
     */
    public function findDisponiblesByCategorieSlug(string $slug): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.categorie', 'c')
            ->andWhere('c.slug = :slug')
            ->andWhere('a.quantity > 0')
            ->setParameter('slug', $slug)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne tous les articles vendus (stock = 0).
     */
    public function findVendus(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.quantity = 0')
            ->orderBy('a.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Article[] Returns an array of Article objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('a.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Article
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
