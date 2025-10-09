<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Enum\OrderStatus;
use App\Repository\ArticleRepository;
use App\Repository\AddressRepository;
use App\Service\InvoiceService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

class CheckoutController extends AbstractController
{
    #[Route('/checkout', name: 'checkout', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(
        SessionInterface $session,
        ArticleRepository $articleRepository,
        Request $request,
        AddressRepository $addressRepo
    ): Response {
        /** @var array<int,int> $cart */
        $cart = $session->get('cart', []);
        if (!$cart) {
            $this->addFlash('info', 'Votre panier est vide.');
            return $this->redirectToRoute('cart_show');
        }

        $items    = [];
        $total    = 0.0;
        $adjusted = false;

        $articles = $articleRepository->findBy(['id' => array_keys($cart)]);
        foreach ($articles as $article) {
            $wanted = max(0, (int)($cart[$article->getId()] ?? 0));
            if ($wanted === 0) {
                continue;
            }

            $stock = max(0, (int) $article->getQuantity());
            if ($stock === 0) {
                unset($cart[$article->getId()]);
                $adjusted = true;
                continue;
            }

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

        if ($adjusted) {
            $session->set('cart', $cart);
            $this->addFlash('info', 'Les quantités ont été ajustées en fonction du stock disponible.');
        }

        if (!$items) {
            $this->addFlash('info', 'Votre panier est vide.');
            return $this->redirectToRoute('cart_show');
        }

        // === Adresses sélectionnées (session) ===
        $user = $this->getUser();

        // Livraison (obligatoire)
        $selectedAddress = null;
        $shippingId = $request->getSession()->get('checkout.address_id');
        if ($shippingId && $user) {
            $selectedAddress = $addressRepo->findOneBy([
                'id'   => $shippingId,
                'user' => $user,
            ]);
        }

        // Facturation : même que livraison (par défaut) ou différente
        $billingSame = (bool) $request->getSession()->get('checkout.billing_same', true);
        $selectedBilling = null;
        if (!$billingSame && $user) {
            $billingId = $request->getSession()->get('checkout.billing_address_id');
            if ($billingId) {
                $selectedBilling = $addressRepo->findOneBy([
                    'id'   => $billingId,
                    'user' => $user,
                ]);
            }
        }

        // On peut confirmer si : adresse de livraison OK
        // et (soit facturation = même, soit adresse de facturation différente choisie)
        $canConfirm = (bool) $selectedAddress && ($billingSame || (bool) $selectedBilling);

        return $this->render('checkout/checkout.html.twig', [
            'items'            => $items,
            'total'            => $total,
            'selectedAddress'  => $selectedAddress,  // livraison
            'billingSame'      => $billingSame,
            'selectedBilling'  => $selectedBilling,  // si différente
            'canConfirm'       => $canConfirm,
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
        InvoiceService         $invoiceService,
        AddressRepository      $addressRepo
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

        // === Sélection d'adresses depuis la session ===
        $shippingId  = $request->getSession()->get('checkout.address_id');
        $billingSame = (bool) $request->getSession()->get('checkout.billing_same', true);
        $billingId   = $request->getSession()->get('checkout.billing_address_id');

        $shipping = null;
        $billing  = null;

        if ($shippingId) {
            $shipping = $addressRepo->findOneBy(['id' => $shippingId, 'user' => $user]);
        }
        if (!$shipping) {
            $this->addFlash('danger', 'Veuillez choisir une adresse de livraison avant de valider la commande.');
            return $this->redirectToRoute('checkout');
        }

        if ($billingSame) {
            $billing = $shipping;
        } else {
            if ($billingId) {
                $billing = $addressRepo->findOneBy(['id' => $billingId, 'user' => $user]);
            }
            if (!$billing) {
                $this->addFlash('danger', 'Veuillez choisir une adresse de facturation.');
                return $this->redirectToRoute('checkout');
            }
        }

        $articles = $articleRepository->findBy(['id' => array_keys($cart)]);

        $conn = $em->getConnection();
        $conn->beginTransaction();

        try {
            $total = 0.0;

            $order = new Order();
            $order->setUser($user);
            $order->setStatus(OrderStatus::EN_COURS);

            // (optionnel) snapshot existant depuis User
            if (method_exists($order, 'setSnapshotFromUser')) {
                $order->setSnapshotFromUser($user);
            }

            // === NEW: snapshots d'adresses sur la commande ===
            $order->setShippingSnapshot([
                'fullName'   => $shipping->getFullName(),
                'line1'      => $shipping->getLine1(),
                'line2'      => $shipping->getLine2(),
                'postalCode' => $shipping->getPostalCode(),
                'city'       => $shipping->getCity(),
                'country'    => $shipping->getCountry(),
                'phone'      => $shipping->getPhone(), // facultatif (ne pas afficher si tu ne veux pas)
            ]);

            $order->setBillingSnapshot([
                'fullName'   => $billing->getFullName(),
                'line1'      => $billing->getLine1(),
                'line2'      => $billing->getLine2(),
                'postalCode' => $billing->getPostalCode(),
                'city'       => $billing->getCity(),
                'country'    => $billing->getCountry(),
                'phone'      => $billing->getPhone(),
            ]);

            foreach ($articles as $article) {
                $qty = max(0, (int)($cart[$article->getId()] ?? 0));
                if ($qty === 0) {
                    continue;
                }

                // Verrou pessimiste
                $em->lock($article, LockMode::PESSIMISTIC_WRITE);

                // Re-vérification du stock
                $current = (int) $article->getQuantity();
                if ($qty > $current) {
                    unset($cart[$article->getId()]);
                    $session->set('cart', $cart);

                    $message = sprintf('Désolé, "%s" vient d’être vendu(e). Votre panier a été mis à jour.', $article->getTitre());
                    $conn->rollBack();
                    return $this->redirectToRoute('cart_show', ['e' => $message]);
                }

                // décrémentation
                $article->setQuantity($current - $qty);

                // snapshot de ligne
                $price    = (float) $article->getPrix();
                $subtotal = $price * $qty;
                $total   += $subtotal;

                $item = new OrderItem();
                $item->setProductName($article->getTitre());
                $item->setUnitPrice(number_format($price, 2, '.', ''));
                $item->setQuantity($qty);

                // image snapshot
                $img = null;
                if (method_exists($article, 'getImageUrl') && $article->getImageUrl()) {
                    $img = $article->getImageUrl();
                } elseif (method_exists($article, 'getImagePath') && $article->getImagePath()) {
                    $img = $article->getImagePath();
                } elseif (method_exists($article, 'getImageName') && $article->getImageName()) {
                    $img = 'uploads/articles/' . ltrim((string) $article->getImageName(), '/');
                } elseif (method_exists($article, 'getImage') && $article->getImage()) {
                    $val = (string) $article->getImage();
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
                $conn->rollBack();
                return $this->redirectToRoute('cart_show', ['e' => 'Votre panier est vide.']);
            }

            $order->setTotal(number_format($total, 2, '.', ''));
            $order->setReference('MV-' . date('Y') . '-' . str_pad((string)random_int(1, 999999), 6, '0', STR_PAD_LEFT));

            $em->persist($order);
            $em->flush();
            $conn->commit();

            // === FACTURES + EMAILS ===
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

            // Nettoyage panier
            $session->remove('cart');

            $this->addFlash('success', 'Votre commande a bien été enregistrée ! Un email de confirmation vous a été envoyé avec votre facture.');
            return $this->redirectToRoute('app_account_orders');

        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            return $this->redirectToRoute('cart_show', ['e' => $e->getMessage()]);
        }
    }
}
