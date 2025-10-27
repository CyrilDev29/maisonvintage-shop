<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(ArticleRepository $articleRepository): Response
    {
        // 9 derniers articles mis en ligne encore disponibles (stock > 0)
        $lastArticles = $articleRepository->createQueryBuilder('a')
            ->andWhere('a.quantity > 0')
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(9)
            ->getQuery()
            ->getResult();

        return $this->render('home/index.html.twig', [
            'articles' => $lastArticles,
        ]);
    }
}
