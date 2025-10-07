<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Enum\OrderStatus;
use App\Repository\ArticleRepository;
use App\Service\InvoiceService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Doctrine\DBAL\LockMode;


class CheckoutController extends AbstractController
{
    #[Route('/checkout', name: 'checkout', methods: ['GET'])]
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
            $qty = max(0, (int)($cart[$article->getId()] ?? 0));
            if ($qty === 0) {
                continue;
            }

            $price = (float)$article->getPrix();
            $subtotal = $price * $qty;
            $total += $subtotal;

            $items[] = [
                'article' => $article,
                'qty' => $qty,
                'price' => $price,
                'subtotal' => $subtotal,
            ];
        }

        if (!$items) {
            $this->addFlash('info', 'Votre panier est vide.');
            return $this->redirectToRoute('cart_show');
        }

        return $this->render('checkout/checkout.html.twig', [
            'items' => $items,
            'total' => $total,
        ]);
    }

    #[Route('/checkout/confirm', name: 'checkout_confirm', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function confirm(
        Request                $request,
        SessionInterface       $session,
        ArticleRepository      $articleRepository,
        EntityManagerInterface $em,
        MailerInterface        $mailer,
        InvoiceService         $invoiceService
    ): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('checkout_confirm', $token)) {
            $this->addFlash('danger', 'Jeton CSRF invalide. Merci de réessayer.');
            return $this->redirectToRoute('checkout');
        }

        /** @var array<int,int> $cart */
        $cart = $session->get('cart', []);
        if (!$cart) {
            $this->addFlash('info', 'Votre panier est vide.');
            return $this->redirectToRoute('cart_show');
        }

        $user = $this->getUser();
        $articles = $articleRepository->findBy(['id' => array_keys($cart)]);


        $conn = $em->getConnection();
        $conn->beginTransaction();

        try {
            $total = 0.0;

            $order = new Order();
            $order->setUser($user);
            $order->setStatus(OrderStatus::EN_COURS);
            $order->setSnapshotFromUser($user);

            foreach ($articles as $article) {
                $qty = max(0, (int)($cart[$article->getId()] ?? 0));
                if ($qty === 0) {
                    continue;
                }

                // 🔒 Verrou pessimiste sur la ligne Article
                $em->lock($article, LockMode::PESSIMISTIC_WRITE);

                //  Re-vérification du stock en base
                $current = (int)$article->getQuantity();
                if ($qty > $current) {
                    throw new \RuntimeException(sprintf(
                        'Stock insuffisant pour "%s" (demandé: %d, disponible: %d).',
                        $article->getTitre(),
                        $qty,
                        $current
                    ));
                }

                // ➖ Décrémenter le stock
                $article->setQuantity($current - $qty);
                // ( @PreUpdate mettra updatedAt, et quantity=0 fera apparaître l’article en "Victime de son succès")

                // Création de la ligne de commande (snapshot)
                $price = (float)$article->getPrix();
                $subtotal = $price * $qty;
                $total += $subtotal;

                $item = new OrderItem();
                $item->setProductName($article->getTitre());
                $item->setUnitPrice(number_format($price, 2, '.', ''));
                $item->setQuantity($qty);

                // Snapshot image robuste (reprend ta logique)
                $img = null;
                if (method_exists($article, 'getImageUrl') && $article->getImageUrl()) {
                    $img = $article->getImageUrl();
                } elseif (method_exists($article, 'getImagePath') && $article->getImagePath()) {
                    $img = $article->getImagePath();
                } elseif (method_exists($article, 'getImageName') && $article->getImageName()) {
                    $img = 'uploads/articles/' . ltrim((string)$article->getImageName(), '/');
                } elseif (method_exists($article, 'getImage') && $article->getImage()) {
                    $val = (string)$article->getImage();
                    if (preg_match('#^https?://#i', $val) || str_starts_with($val, '/')) {
                        $img = $val;
                    } else {
                        $img = 'uploads/articles/' . ltrim($val, '/');
                    }
                }
                $item->setProductImage($img);

                $order->addItem($item);
            }

            if ($order->getItems()->count() === 0) {
                throw new \RuntimeException('Impossible de créer la commande : panier vide.');
            }

            $order->setTotal(number_format($total, 2, '.', ''));
            $order->setReference('MV-' . date('Y') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT));

            $em->persist($order);
            $em->flush(); // flush  pour figer l’ID commande + décrémentation stock


            $conn->commit();

            // === FACTURE + EMAILS (inchangé) ===
            $clientPdfPath = $invoiceService->generate($order, false);
            $sellerPdfPath = $invoiceService->generate($order, true);

            $from = $this->getParameter('app.contact_from') ?? 'no-reply@maisonvintage.test';

            $emailClient = (new TemplatedEmail())
                ->from($from)
                ->to($user->getEmail())
                ->subject('Confirmation de votre commande ' . $order->getReference())
                ->htmlTemplate('emails/order_confirmation.html.twig')
                ->context(['order' => $order, 'user' => $user])
                ->attachFromPath($clientPdfPath, sprintf('Facture-%s.pdf', $order->getReference()));
            $mailer->send($emailClient);

            $sellerTo = $this->getParameter('app.seller_email') ?? $from;
            $emailSeller = (new TemplatedEmail())
                ->from($from)
                ->to($sellerTo)
                ->subject('Copie facture — ' . $order->getReference())
                ->html('<p>Copie vendeur à archiver.</p>')
                ->attachFromPath($sellerPdfPath, sprintf('Facture-%s-copie.pdf', $order->getReference()));
            $mailer->send($emailSeller);

            if (method_exists($order, 'markInvoiceSent')) {
                $order->markInvoiceSent();
                $em->flush();
            }
            // === /FACTURE ===

            // Nettoyage panier
            $session->remove('cart');

            $this->addFlash('success', 'Votre commande a bien été enregistrée ! Un email de confirmation vous a été envoyé avec votre facture.');
            return $this->redirectToRoute('app_account_orders');

        } catch (\Throwable $e) {
            // Rollback si problème de stock ou autre
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->addFlash('danger', $e->getMessage());
            return $this->redirectToRoute('cart_show');
        }
    }
}
