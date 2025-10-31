<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ArticleController extends AbstractController
{
    /**
     * Route canonique : /article/{id}-{slug}
     * On charge par ID (fiable même si plusieurs articles ont le même slug).
     * Si le slug ne correspond pas à celui stocké, on redirige en 301 vers l’URL canonique.
     */
    #[Route('/article/{id}-{slug}', name: 'article_show', requirements: ['id' => '\d+'])]
    public function show(int $id, string $slug, ArticleRepository $articleRepository): Response
    {
        $article = $articleRepository->find($id);

        if (!$article) {
            throw $this->createNotFoundException('Article introuvable');
        }

        if ($article->getSlug() !== $slug) {
            return $this->redirectToRoute('article_show', [
                'id'   => $article->getId(),
                'slug' => $article->getSlug() ?? '',
            ], 301);
        }

        return $this->render('article/show.html.twig', [
            'article' => $article,
        ]);
    }

    /**
     * Route legacy : /article/{slug}
     * On conserve pour compat. Si collision de slug, on prend le plus récent
     * et on redirige vers la route canonique.
     */
    #[Route('/article/{slug}', name: 'article_show_legacy', priority: -10)]
    public function showLegacy(string $slug, ArticleRepository $articleRepository): Response
    {
        $article = $articleRepository->findOneBy(['slug' => $slug], ['createdAt' => 'DESC']);

        if (!$article) {
            throw $this->createNotFoundException('Article introuvable');
        }

        return $this->redirectToRoute('article_show', [
            'id'   => $article->getId(),
            'slug' => $article->getSlug() ?? '',
        ], 301);
    }

    /**
     * Accès par ID pur : redirige vers l’URL canonique id-slug.
     */
    #[Route('/article/id/{id}', name: 'article_show_by_id', requirements: ['id' => '\d+'])]
    public function showById(int $id, ArticleRepository $articleRepository): Response
    {
        $article = $articleRepository->find($id);

        if (!$article) {
            throw $this->createNotFoundException('Article introuvable');
        }

        return $this->redirectToRoute('article_show', [
            'id'   => $article->getId(),
            'slug' => $article->getSlug() ?? '',
        ], 301);
    }
}
