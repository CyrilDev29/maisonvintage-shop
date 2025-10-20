<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SearchController extends AbstractController
{
    #[Route('/recherche', name: 'search', methods: ['GET'])]
    public function index(
        Request $request,
        ArticleRepository $articles,
        CategorieRepository $categories,
        PaginatorInterface $paginator
    ): Response {
        $q    = trim((string) $request->query->get('q', ''));
        $cat  = $request->query->get('cat'); // slug
        $pmin = $request->query->get('pmin');
        $pmax = $request->query->get('pmax');

        $pmin = ($pmin !== null && $pmin !== '') ? (int) $pmin : null;
        $pmax = ($pmax !== null && $pmax !== '') ? (int) $pmax : null;

        // Toujours filtrer sur les articles disponibles
        $query = $articles->buildSearchQuery(
            $q ?: null,
            $cat ?: null,
            $pmin,
            $pmax,
            true
        );

        $page    = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;

        $pagination = $paginator->paginate($query, $page, $perPage);

        return $this->render('search/index.html.twig', [
            'q'           => $q,
            'pagination'  => $pagination,
            'categories'  => $categories->findBy([], ['nom' => 'ASC']),
            'current'     => [
                'cat'   => $cat,
                'pmin'  => $pmin,
                'pmax'  => $pmax,
            ],
        ]);
    }

    #[Route('/autocomplete', name: 'search_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request, ArticleRepository $articles): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        if ($q === '') return $this->json([]);

        $query = $articles->buildSearchQuery($q, null, null, null, true);
        $query->setMaxResults(10);
        $results = $query->getResult();

        $suggestions = [];
        foreach ($results as $a) {
            $suggestions[] = [
                'titre' => $a->getTitre(),
                'slug'  => $a->getSlug(),
                'cat'   => $a->getCategorie()?->getNom(),
            ];
        }

        return $this->json($suggestions);
    }
}
