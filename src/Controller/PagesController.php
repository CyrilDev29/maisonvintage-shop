<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\ArticleRepository;

class PagesController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('pages/index.html.twig');
    }
    #[Route('/nouveau-cocon', name: 'nouveau_cocon')]
    public function nouveauCocon(): Response
    {
        return $this->render('pages/nouveau_cocon.html.twig');
    }

    #[Route('/victime-de-son-succes', name: 'victime_succes')]
    public function victimeSucces(ArticleRepository $articleRepository): Response
    {
        $articlesVendus = $articleRepository->findBy(['quantity' => 0]);

        return $this->render('pages/victime_succes.html.twig', [
            'articles' => $articlesVendus
        ]);
    }

    #[Route('/contact', name: 'contact')]
    public function contact(): Response
    {
        return $this->render('pages/contact.html.twig');
    }

    #[Route('/panier', name: 'cart_index')]
    public function cart(): Response
    {
        return $this->render('pages/cart.html.twig');
    }
}
