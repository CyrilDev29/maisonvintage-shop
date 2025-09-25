<?php

namespace App\Controller;

use App\Repository\CategorieRepository;
use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CategorieController extends AbstractController
{
    #[Route('/categorie/{slug}', name: 'categorie_show')]
    public function show(
        string $slug,
        Request $request,
        CategorieRepository $categorieRepository,
        ArticleRepository $articleRepository
    ): Response {
        $categorie = $categorieRepository->findOneBy(['slug' => $slug]);

        if (!$categorie) {
            throw $this->createNotFoundException('Catégorie introuvable');
        }

        // Pagination simple
        $page   = max(1, (int) $request->query->get('page', 1));
        $limit  = 12;
        $offset = ($page - 1) * $limit;

        $items = $articleRepository->createQueryBuilder('a')
            ->andWhere('a.categorie = :cat')
            ->setParameter('cat', $categorie)
            ->orderBy('a.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $total = (int) $articleRepository->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.categorie = :cat')
            ->setParameter('cat', $categorie)
            ->getQuery()
            ->getSingleScalarResult();

        $pages = (int) ceil($total / $limit);

        return $this->render('categorie/show.html.twig', [
            'categorie' => $categorie,
            'articles'  => $items,
            'page'      => $page,
            'pages'     => $pages,
            'total'     => $total,
        ]);
    }
}
