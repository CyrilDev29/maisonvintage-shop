<?php
// src/Controller/CheckoutController.php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Enum\OrderStatus;
use App\Repository\ArticleRepository;
use App\Repository\AddressRepository;
use App\Service\StripePaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Tunnel de commande.
 * Ajout non cassant : enregistre paymentMethod + reservedUntil (durées paramétrables).
 */
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

        $user = $this->getUser();

        $selectedAddress = null;
        $shippingId = $request->getSession()->get('checkout.address_id');
        if ($shippingId && $user) {
            $selectedAddress = $addressRepo->findOneBy([
                'id'   => $shippingId,
                'user' => $user,
            ]);
        }

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

        $canConfirm = (bool) $selectedAddress && ($billingSame || (bool) $selectedBilling);

        return $this->render('checkout/checkout.html.twig', [
            'items'            => $items,
            'total'            => $total,
            'selectedAddress'  => $selectedAddress,
            'billingSame'      => $billingSame,
            'selectedBilling'  => $selectedBilling,
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
        AddressRepository      $addressRepo,
        StripePaymentService   $stripe,
        MailerInterface        $mailer
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        // CSRF basique pour le POST de confirmation.
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

        // Récupération des adresses depuis la session (sélection préalable).
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

        // Moyen de paiement choisi depuis le formulaire
        $paymentMethod = (string) ($request->request->get('payment_method') ?? 'card'); // 'bank_transfer' | 'card' | 'paypal'

        // Re-contrôle du stock juste avant création de commande.
        $articles = $articleRepository->findBy(['id' => array_keys($cart)]);
        foreach ($articles as $article) {
            $qtyInCart = max(0, (int)($cart[$article->getId()] ?? 0));
            if ($qtyInCart === 0) {
                continue;
            }
            if ($qtyInCart > (int) $article->getQuantity()) {
                $this->addFlash('danger', sprintf('Le stock de "%s" vient de changer. Merci de vérifier votre panier.', $article->getTitre()));
                return $this->redirectToRoute('cart_show');
            }
        }

        $conn = $em->getConnection();
        $conn->beginTransaction();

        try {
            $total = 0.0;

            $order = new Order();
            $order->setUser($user);
            $order->setStatus(OrderStatus::EN_ATTENTE_PAIEMENT);

            // Enregistre le moyen de paiement et la deadline via paramètres configurables
            if (method_exists($order, 'setPaymentMethod')) {
                $order->setPaymentMethod($paymentMethod);
            }
            if (method_exists($order, 'setReservedUntil')) {
                $bankHours  = (int) ($this->getParameter('maisonvintage.ttl.bank_transfer_hours') ?? 72);
                $cardMin    = (int) ($this->getParameter('maisonvintage.ttl.card_minutes') ?? 30);
                $ttlMinutes = ($paymentMethod === 'bank_transfer') ? ($bankHours * 60) : $cardMin;

                $order->setReservedUntil(
                    (new \DateTimeImmutable())->modify(sprintf('+%d minutes', max(1, $ttlMinutes)))
                );
            }

            if (method_exists($order, 'setSnapshotFromUser')) {
                $order->setSnapshotFromUser($user);
            }

            $order->setShippingSnapshot([
                'fullName'   => $shipping->getFullName(),
                'line1'      => $shipping->getLine1(),
                'line2'      => $shipping->getLine2(),
                'postalCode' => $shipping->getPostalCode(),
                'city'       => $shipping->getCity(),
                'country'    => $shipping->getCountry(),
                'phone'      => $shipping->getPhone(),
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

                $price    = (float) $article->getPrix();
                $subtotal = $price * $qty;
                $total   += $subtotal;

                $item = new OrderItem();
                if (method_exists($item, 'setProductId')) {
                    $item->setProductId($article->getId());
                }
                $item->setProductName($article->getTitre());
                $item->setUnitPrice(number_format($price, 2, '.', ''));
                $item->setQuantity($qty);

                // Image produit (robuste)
                $img = null;
                if (method_exists($article, 'getImageUrl') && $article->getImageUrl()) {
                    $img = $article->getImageUrl();
                } elseif (method_exists($article, 'getImagePath') && $article->getImagePath()) {
                    $img = $article->getImagePath();
                } elseif (method_exists($article, 'getImageName') && $article->getImageName()) {
                    $img = '/uploads/articles/' . ltrim((string) $article->getImageName(), '/');
                } elseif (method_exists($article, 'getImage') && $article->getImage()) {
                    $val = (string) $article->getImage();
                    if (preg_match('#^https?://#i', $val) || str_starts_with($val, '/')) {
                        $img = $val;
                    } else {
                        $img = '/uploads/articles/' . ltrim($val, '/');
                    }
                }
                if (method_exists($item, 'setProductImage')) {
                    $item->setProductImage($img);
                }

                $order->addItem($item);
            }

            if ($order->getItems()->count() === 0) {
                $conn->rollBack();
                $this->addFlash('info', 'Votre panier est vide.');
                return $this->redirectToRoute('cart_show');
            }

            $order->setTotal(number_format($total, 2, '.', ''));
            $order->setReference('MV-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT));

            $em->persist($order);
            $em->flush();

            // Cas virement : décrémentation immédiate + mail d’instructions
            if ($paymentMethod === 'bank_transfer') {
                foreach ($articles as $article) {
                    $qty = max(0, (int)($cart[$article->getId()] ?? 0));
                    if ($qty === 0) continue;
                    $newQty = max(0, (int)$article->getQuantity() - $qty);
                    $article->setQuantity($newQty);
                    $em->persist($article);
                }
                $em->flush();
                $conn->commit();

                $session->remove('cart');

                $from       = (string) ($this->getParameter('app.contact_from') ?? 'no-reply@maisonvintage.test');
                $clientTo   = method_exists($user, 'getEmail') ? (string) $user->getEmail() : null;
                $bankHolder = (string) ($this->getParameter('bank.holder')   ?? '');
                $bankName   = (string) ($this->getParameter('bank.bankname') ?? '');
                $bankIban   = (string) ($this->getParameter('bank.iban')     ?? '');
                $bankBic    = (string) ($this->getParameter('bank.bic')      ?? '');

                if ($clientTo) {
                    $emailClient = (new TemplatedEmail())
                        ->from(new Address($from, 'Maison Vintage'))
                        ->to($clientTo)
                        ->subject('Confirmation de votre commande ' . $order->getReference() . ' — en attente de virement')
                        ->htmlTemplate('emails/order_confirmation.html.twig')
                        ->context([
                            'order'         => $order,
                            'user'          => $user,
                            'bank_holder'   => $bankHolder,
                            'bank_bankname' => $bankName,
                            'bank_iban'     => $bankIban,
                            'bank_bic'      => $bankBic,
                        ]);
                    try { $mailer->send($emailClient); } catch (\Throwable) { /* non bloquant */ }
                }

                return $this->redirectToRoute('paiement_bank_transfer', [
                    'ref' => $order->getReference(),
                    'id'  => $order->getId(),
                ]);
            }

            // CB/PayPal : redirection Stripe. Traitement final via webhook.
            $conn->commit();

            $lineItems = [];
            foreach ($order->getItems() as $it) {
                $unitCents = (int) \round((float) $it->getUnitPrice() * 100);
                $qty       = max(1, (int) $it->getQuantity());
                $lineItems[] = [
                    'name'        => $it->getProductName(),
                    'unit_amount' => $unitCents,
                    'quantity'    => $qty,
                ];
            }

            $email = method_exists($user, 'getEmail') ? $user->getEmail() : null;

            $sessionStripe = $stripe->createCheckoutSession(
                $lineItems,
                $order->getReference(),
                $email
            );

            return $this->redirectToRoute('checkout_redirect', [
                'sessionId'  => $sessionStripe->id,
                'sessionUrl' => $sessionStripe->url,
            ]);

        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->addFlash('danger', 'Une erreur est survenue lors de la validation du panier : ' . $e->getMessage());
            return $this->redirectToRoute('cart_show');
        }
    }

    #[Route('/checkout/redirect/{sessionId}', name: 'checkout_redirect', methods: ['GET'])]
    public function redirectToStripe(string $sessionId, Request $request, ParameterBagInterface $params): Response
    {
        $stripePublicKey = (string) $params->get('stripe.public_key');
        $sessionUrl      = (string) $request->query->get('sessionUrl', '');

        return $this->render('checkout/redirect.html.twig', [
            'sessionId'       => $sessionId,
            'sessionUrl'      => $sessionUrl,
            'stripePublicKey' => $stripePublicKey,
        ]);
    }
}
