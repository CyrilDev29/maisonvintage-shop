<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Repository\CategorieRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SitemapController extends AbstractController
{
    // Route qui repond a maisonvintage.fr/sitemap.xml
    #[Route('/sitemap.xml', name: 'sitemap')]
    public function index(
        ArticleRepository    $articleRepository,
        CategorieRepository  $categorieRepository
    ): Response {
        // On recupere tous les articles et toutes les categories
        $articles   = $articleRepository->findBy([], ['createdAt' => 'DESC']);
        $categories = $categorieRepository->findAll();

        // On rend le template XML et on force le bon Content-Type
        $response = new Response(
            $this->renderView('sitemap.xml.twig', [
                'articles'   => $articles,
                'categories' => $categories,
            ]),
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8']
        );

        // Cache navigateur/CDN d'1 heure pour eviter de regenerer a chaque visite
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
