<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Entity\Categorie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\ORM\Query;

class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

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

    public function buildSearchQuery(
        ?string $q,
        ?string $categorieSlug,
        ?int $prixMin,
        ?int $prixMax,
        ?bool $disponibleUniquement
    ): Query {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.categorie', 'c')->addSelect('c');

        if ($categorieSlug) {
            $qb->andWhere('c.slug = :cslug')->setParameter('cslug', $categorieSlug);
        }
        if ($prixMin !== null) {
            $qb->andWhere('a.prix >= :pmin')->setParameter('pmin', $prixMin);
        }
        if ($prixMax !== null) {
            $qb->andWhere('a.prix <= :pmax')->setParameter('pmax', $prixMax);
        }
        if ($disponibleUniquement === true) {
            $qb->andWhere('a.quantity > 0');
        }

        $hasKeywordsColumn = $this->tableHasColumn('article', 'keywords');

        $terms = [];
        if ($q) {
            $q = trim(mb_strtolower($q));
            if ($q !== '') {
                $terms = array_values(array_filter(preg_split('/\s+/', $q) ?: [], static fn($t) => $t !== ''));
                foreach ($terms as $i => $term) {
                    $param = "t$i";
                    $ors = [
                        "LOWER(COALESCE(a.titre,'')) LIKE :$param",
                        "LOWER(COALESCE(a.description,'')) LIKE :$param",
                        "LOWER(COALESCE(c.nom,'')) LIKE :$param",
                    ];
                    if ($hasKeywordsColumn) {
                        $ors[] = "LOWER(COALESCE(a.keywords,'')) LIKE :$param";
                    }
                    $qb->andWhere($qb->expr()->orX(...$ors))
                        ->setParameter($param, '%'.$term.'%');
                }
            }
        }

        if ($terms) {
            $scoreExpr = [];
            foreach ($terms as $i => $term) {
                $p = "s$i";
                $qb->setParameter($p, '%'.$term.'%');
                $scoreExpr[] = $hasKeywordsColumn
                    ? "(CASE WHEN LOWER(COALESCE(a.titre,'')) LIKE :$p THEN 4
                               WHEN LOWER(COALESCE(a.description,'')) LIKE :$p THEN 3
                               WHEN LOWER(COALESCE(c.nom,'')) LIKE :$p THEN 2
                               WHEN LOWER(COALESCE(a.keywords,'')) LIKE :$p THEN 1
                               ELSE 0 END)"
                    : "(CASE WHEN LOWER(COALESCE(a.titre,'')) LIKE :$p THEN 4
                               WHEN LOWER(COALESCE(a.description,'')) LIKE :$p THEN 3
                               WHEN LOWER(COALESCE(c.nom,'')) LIKE :$p THEN 2
                               ELSE 0 END)";
            }
            $qb->addSelect(implode(' + ', $scoreExpr) . ' AS HIDDEN relevance')
                ->addOrderBy('relevance', 'DESC');
        }

        $qb->addOrderBy('a.createdAt', 'DESC');

        return $qb->getQuery();
    }

    public function findVendus(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.quantity = 0')
            ->orderBy('a.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            $sm = $this->getEntityManager()->getConnection()->createSchemaManager();
            if (!$sm->tablesExist([$table])) {
                return false;
            }
            $tbl = $sm->introspectTable($table);
            return $tbl->hasColumn($column);
        } catch (\Throwable) {
            return false;
        }
    }
}
