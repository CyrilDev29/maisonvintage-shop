<?php

namespace App\Controller;

use App\Repository\ArticleRepository;
use App\Service\StripePaymentService;
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

        $items     = [];
        $total     = 0.0;
        $adjusted  = false;
        $removedId = [];

        if (!empty($cart)) {
            $ids = array_keys($cart);

            // Charger uniquement les articles encore existants
            $articles = $articleRepository->findBy(['id' => $ids]);
            $foundIds = array_map(static fn($a) => $a->getId(), $articles);

            // Supprimer du panier les IDs inexistants en base
            foreach ($ids as $id) {
                if (!in_array($id, $foundIds, true)) {
                    unset($cart[$id]);
                    $adjusted   = true;
                    $removedId[] = $id;
                }
            }

            // Construire la vue et ajuster les quantités selon le stock
            foreach ($articles as $article) {
                $wanted = max(0, (int)($cart[$article->getId()] ?? 0));
                if ($wanted === 0) {
                    continue;
                }

                $stock = max(0, (int) $article->getQuantity());

                // Si plus de stock → retirer la ligne
                if ($stock === 0) {
                    unset($cart[$article->getId()]);
                    $adjusted = true;
                    continue;
                }

                // Ajuster la quantité à la dispo réelle
                $qty = min($wanted, $stock);
                if ($qty !== $wanted) {
                    $cart[$article->getId()] = $qty;
                    $adjusted = true;
                }

                $price    = (float) $article->getPrix();
                $subtotal = $price * $qty;
                $total   += $subtotal;

                $items[] = [
                    'article'  => $article,
                    'qty'      => $qty,
                    'price'    => $price,
                    'subtotal' => $subtotal,
                ];
            }

            // Si modifié, sauvegarder la session + informer
            if ($adjusted) {
                $session->set('cart', $cart);
                $this->addFlash('info', 'Le panier a été ajusté en fonction des stocks et des articles disponibles.');
            }
        }

        // Message si panier désormais vide
        if (empty($cart)) {
            $this->addFlash('info', 'Votre panier est vide.');
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
            unset($cart[$id]);
            $session->set('cart', $cart);
            $this->addFlash('info', 'Cet article n’existe plus et a été retiré du panier.');
            return $this->redirectToRoute('cart_show');
        }

        $stock   = (int) $article->getQuantity();
        $current = (int) $cart[$id];

        if ($stock <= 0) {
            unset($cart[$id]);
            $session->set('cart', $cart);
            $this->addFlash('info', 'Cet article est en rupture et a été retiré du panier.');
        } elseif ($current < $stock) {
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
            unset($cart[$id]);
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

    /**
     * (Optionnel / Secours) Vide le panier après succès Stripe si appelé directement.
     * Idempotent : ne fait rien si session_id absent, invalide, ou paiement non "paid".
     */
    #[Route('/panier/clear-after-success', name: 'cart_clear_after_success', methods: ['GET'])]
    public function clearAfterCheckoutSuccess(
        Request $request,
        SessionInterface $session,
        StripePaymentService $stripe
    ): Response {
        $sessionId = (string) $request->query->get('session_id', '');
        if ($sessionId !== '') {
            try {
                $checkout = $stripe->retrieveCheckoutSession($sessionId);
                if (($checkout->payment_status ?? null) === 'paid') {
                    $session->remove('cart');
                    $this->addFlash('success', 'Merci pour votre achat. Votre panier a été vidé.');
                }
            } catch (\Throwable $e) {
                // Pas de vidage si erreur Stripe
            }
        }

        return $this->redirectToRoute('cart_show');
    }
}
