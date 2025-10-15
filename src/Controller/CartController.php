<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

class CartController extends AbstractController
{
    #[Route('/panier', name: 'cart_show')]
    public function show(SessionInterface $session, ArticleRepository $articleRepository): Response
    {
        /** @var array<int,int> $cart idArticle => qty */
        $cart = $session->get('cart', []);

        $items = [];
        $total = 0.0;

        if (!empty($cart)) {
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
        }

        return $this->render('cart/cart.html.twig', [
            'items' => $items,
            'total' => $total,
        ]);
    }

    #[Route('/panier/ajouter/{id}', name: 'cart_add', methods: ['POST'])]
    public function add(
        int $id,
        Request $request,
        SessionInterface $session,
        ArticleRepository $articleRepository
    ): Response {
        // ✅ CSRF sur ajout au panier
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('cart_add_'.$id, $token)) {
            $this->addFlash('danger', 'Action refusée (sécurité).');
            return $this->redirectToRoute('cart_show');
        }

        $qtyToAdd = max(1, (int) $request->request->get('qty', 1));

        $article = $articleRepository->find($id);
        if (!$article) {
            $this->addFlash('danger', 'Article introuvable.');
            return $this->redirectToRoute('cart_show');
        }

        $stock = (int) $article->getQuantity();
        if ($stock <= 0) {
            $this->addFlash('warning', 'Article en rupture de stock.');
            return $this->redirectToRoute('cart_show');
        }

        /** @var array<int,int> $cart */
        $cart = $session->get('cart', []);
        $currentQty = (int) ($cart[$id] ?? 0);

        // Nouvelle quantité dans le panier = min(stock, existant + demandé)
        $newQty = min($stock, $currentQty + $qtyToAdd);
        $added  = $newQty - $currentQty;

        $cart[$id] = $newQty;
        $session->set('cart', $cart);

        if ($added > 0) {
            $this->addFlash('success', sprintf(
                '%d article%s ajouté%s au panier (stock max: %d).',
                $added,
                $added > 1 ? 's' : '',
                $added > 1 ? 's' : '',
                $stock
            ));
        } else {
            $this->addFlash('info', 'Quantité maximale déjà atteinte pour cet article.');
        }

        return $this->redirectToRoute('cart_show');
    }

    #[Route('/panier/augmenter/{id}', name: 'cart_inc', methods: ['POST'])]
    public function inc(int $id, Request $request, SessionInterface $session, ArticleRepository $articleRepository): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('cart_inc_'.$id, $token)) {
            $this->addFlash('danger', 'Action refusée (sécurité).');
            return $this->redirectToRoute('cart_show');
        }

        /** @var array<int,int> $cart */
        $cart = $session->get('cart', []);

        if (!isset($cart[$id])) {
            $this->addFlash('info', 'Article non présent dans le panier.');
            return $this->redirectToRoute('cart_show');
        }

        $article = $articleRepository->find($id);
        if (!$article) {
            $this->addFlash('danger', 'Article introuvable.');
            return $this->redirectToRoute('cart_show');
        }

        $stock = (int) $article->getQuantity();
        $current = (int) $cart[$id];

        if ($current < $stock) {
            $cart[$id] = $current + 1;
            $session->set('cart', $cart);
            $this->addFlash('success', 'Quantité augmentée.');
        } else {
            $this->addFlash('info', 'Quantité maximale atteinte pour cet article.');
        }

        return $this->redirectToRoute('cart_show');
    }

    #[Route('/panier/diminuer/{id}', name: 'cart_dec', methods: ['POST'])]
    public function dec(int $id, Request $request, SessionInterface $session): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('cart_dec_'.$id, $token)) {
            $this->addFlash('danger', 'Action refusée (sécurité).');
            return $this->redirectToRoute('cart_show');
        }

        /** @var array<int,int> $cart */
        $cart = $session->get('cart', []);

        if (!isset($cart[$id])) {
            $this->addFlash('info', 'Article non présent dans le panier.');
            return $this->redirectToRoute('cart_show');
        }

        $current = (int) $cart[$id];

        if ($current > 1) {
            $cart[$id] = $current - 1;
        } else {
            unset($cart[$id]); // si on passe sous 1, on retire la ligne
        }

        $session->set('cart', $cart);
        return $this->redirectToRoute('cart_show');
    }

    #[Route('/panier/supprimer/{id}', name: 'cart_remove', methods: ['POST'])]
    public function remove(int $id, Request $request, SessionInterface $session): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('cart_remove_'.$id, $token)) {
            $this->addFlash('danger', 'Action refusée (sécurité).');
            return $this->redirectToRoute('cart_show');
        }

        /** @var array<int,int> $cart */
        $cart = $session->get('cart', []);
        unset($cart[$id]);
        $session->set('cart', $cart);
        $this->addFlash('info', 'Article retiré du panier.');
        return $this->redirectToRoute('cart_show');
    }

    #[Route('/panier/vider', name: 'cart_clear', methods: ['POST'])]
    public function clear(Request $request, SessionInterface $session): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('cart_clear', $token)) {
            $this->addFlash('danger', 'Action refusée (sécurité).');
            return $this->redirectToRoute('cart_show');
        }

        $session->remove('cart');
        $this->addFlash('info', 'Panier vidé.');
        return $this->redirectToRoute('cart_show');
    }
}
