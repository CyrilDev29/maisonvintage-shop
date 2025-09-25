<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class CheckoutController extends AbstractController
{
    #[Route('/checkout', name: 'checkout')]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(SessionInterface $session, ArticleRepository $articleRepository): Response
    {
        /** @var array<int,int> $cart */
        $cart = $session->get('cart', []);

        if (!$cart) {
            $this->addFlash('info', 'Votre panier est vide.');
            return $this->redirectToRoute('cart_show');
        }

        $items = [];
        $total = 0.0;

        $articles = $articleRepository->findBy(['id' => array_keys($cart)]);
        foreach ($articles as $article) {
            $qty = $cart[$article->getId()] ?? 0;
            $price = (float) $article->getPrix();
            $subtotal = $price * $qty;
            $total += $subtotal;

            $items[] = [
                'article'  => $article,
                'qty'      => $qty,
                'price'    => $price,
                'subtotal' => $subtotal,
            ];
        }

        return $this->render('checkout/checkout.html.twig', [
            'items' => $items,
            'total' => $total,
        ]);

    }
}
