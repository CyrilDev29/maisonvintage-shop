<?php
// src/Controller/CheckoutController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Enum\OrderStatus;
use App\Repository\ArticleRepository;
use App\Repository\AddressRepository;
use App\Service\StripePaymentService;
use App\Service\Shipping\ShippingManager;
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

class CheckoutController extends AbstractController
{
    #[Route('/checkout', name: 'checkout', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(
        SessionInterface   $session,
        ArticleRepository  $articleRepository,
        Request            $request,
        AddressRepository  $addressRepo,
        ShippingManager    $shippingManager
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
            if ($wanted === 0) continue;

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

        // Adresse(s)
        $selectedAddress = null;
        $shippingId = $request->getSession()->get('checkout.address_id');
        if ($shippingId && $user) {
            $selectedAddress = $addressRepo->findOneBy(['id' => $shippingId, 'user' => $user]);
        }

        $billingSame = (bool) $request->getSession()->get('checkout.billing_same', true);
        $selectedBilling = null;
        if (!$billingSame && $user) {
            $billingId = $request->getSession()->get('checkout.billing_address_id');
            if ($billingId) {
                $selectedBilling = $addressRepo->findOneBy(['id' => $billingId, 'user' => $user]);
            }
        }

        $canConfirm = (bool) $selectedAddress && ($billingSame || (bool) $selectedBilling);

        // Devis multi-transporteurs (stub)
        $shippingOptions = [];
        $shippingSelection = (array) $request->getSession()->get('checkout.shipping', []);
        if ($selectedAddress) {
            $cartLines = [];
            foreach ($items as $it) {
                $a = $it['article'];
                $qty = (int) $it['qty'];
                // On tente de lire le poids (kg) et le convertir en grammes
                $gr = 0;
                if (\method_exists($a, 'getWeightKg') && $a->getWeightKg() !== null) {
                    $kg = (float) $a->getWeightKg();
                    if ($kg > 0) {
                        $gr = (int) round($kg * 1000);
                    }
                }
                $cartLines[] = [
                    'qty' => $qty,
                    'weight_gr' => $gr > 0 ? $gr : null,
                ];
            }

            $quoteAll = $shippingManager->quoteAll($cartLines, $selectedAddress);
            foreach ($quoteAll->options as $opt) {
                $shippingOptions[] = [
                    'carrier'      => $opt->carrier->value,
                    'code'         => $opt->code,
                    'label'        => $opt->label,
                    'amount_cents' => $opt->amountCents,
                    'metadata'     => $opt->metadata ?? [],
                ];
            }
        }

        return $this->render('checkout/checkout.html.twig', [
            'items'             => $items,
            'total'             => $total,
            'selectedAddress'   => $selectedAddress,
            'billingSame'       => $billingSame,
            'selectedBilling'   => $selectedBilling,
            'canConfirm'        => $canConfirm,
            'shippingOptions'   => $shippingOptions,
            'shippingSelection' => $shippingSelection,
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

        // CSRF
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

        // Adresses
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

        // Paiement choisi
        $paymentMethod = (string) ($request->request->get('payment_method') ?? 'card');

        // ========= Expédition OBLIGATOIRE côté serveur =========
        $postedCarrier     = (string) ($request->request->get('shipping_carrier') ?? '');
        $postedMethod      = (string) ($request->request->get('shipping_method') ?? '');
        $postedAmountCents = $request->request->get('shipping_amount_cents');
        $postedRelayId     = (string) ($request->request->get('shipping_relay_id') ?? '');

        // manquant ?
        if ($postedCarrier === '' || $postedMethod === '' || $postedAmountCents === null) {
            $this->addFlash('danger', 'Veuillez sélectionner un mode d’expédition.');
            return $this->redirectToRoute('checkout');
        }

        $shippingAmountCents = max(0, (int) $postedAmountCents);

        // Si la méthode choisie exige un point relais, on impose le relayId
        $needsRelay = \in_array($postedMethod, ['RELAIS', 'POINT_RELAIS'], true);
        if ($needsRelay && $postedRelayId === '') {
            $this->addFlash('danger', 'Veuillez sélectionner un point relais.');
            return $this->redirectToRoute('checkout');
        }

        // Stock
        $articles = $articleRepository->findBy(['id' => array_keys($cart)]);
        foreach ($articles as $article) {
            $qtyInCart = max(0, (int)($cart[$article->getId()] ?? 0));
            if ($qtyInCart === 0) continue;
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

            // Paiement choisi + réservation
            if (method_exists($order, 'setPaymentMethod')) {
                $order->setPaymentMethod($paymentMethod);
            }
            if (method_exists($order, 'setReservedUntil')) {
                $bankHours  = (int) ($this->getParameter('maisonvintage.ttl.bank_transfer_hours') ?? 72);
                $cardMin    = (int) ($this->getParameter('maisonvintage.ttl.card_minutes') ?? 30);
                $ttlMinutes = ($paymentMethod === 'bank_transfer') ? ($bankHours * 60) : $cardMin;
                $order->setReservedUntil( (new \DateTimeImmutable())->modify(sprintf('+%d minutes', max(1, $ttlMinutes))) );
            }

            // Snapshots adresses
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

            // Enregistre le choix expédition (et session)
            $shippingSelection = [
                'carrier'      => $postedCarrier,
                'method'       => $postedMethod,
                'amount_cents' => $shippingAmountCents,
                'relay_id'     => $postedRelayId ?: null,
            ];
            $request->getSession()->set('checkout.shipping', $shippingSelection);

            if (method_exists($order, 'setShippingCarrier'))       { $order->setShippingCarrier($postedCarrier); }
            if (method_exists($order, 'setShippingMethod'))        { $order->setShippingMethod($postedMethod); }
            if (method_exists($order, 'setShippingAmountCents'))   { $order->setShippingAmountCents($shippingAmountCents); }
            if (method_exists($order, 'setShippingRelayId'))       { $order->setShippingRelayId($postedRelayId ?: null); }

            // Lignes
            foreach ($articles as $article) {
                $qty = max(0, (int)($cart[$article->getId()] ?? 0));
                if ($qty === 0) continue;

                $price    = (float) $article->getPrix();

                // Validation du prix (sécurité anti-fraude)
                if ($price <= 0) {
                    // Prix invalide, on ignore cet article
                    continue;
                }

                $subtotal = $price * $qty;
                $total   += $subtotal;

                $item = new OrderItem();
                if (method_exists($item, 'setProductId')) {
                    $item->setProductId($article->getId());
                }
                $item->setProductName($article->getTitre());
                $item->setUnitPrice(number_format($price, 2, '.', ''));
                $item->setQuantity($qty);

                // Image produit (version simplifiée)
                $img = null;
                if ($article->getImage()) {
                    $img = '/uploads/articles/' . ltrim($article->getImage(), '/');
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

            // On conserve getTotal() pour les articles ; le port est séparé
            $order->setTotal(number_format($total, 2, '.', ''));
            $order->setReference('MV-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT));



            $em->persist($order);
            $em->flush();

            // Virement : décrémentation immédiate + mail
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
                    try { $mailer->send($emailClient); } catch (\Throwable) {}
                }

                return $this->redirectToRoute('paiement_bank_transfer', [
                    'ref' => $order->getReference(),
                    'id'  => $order->getId(),
                ]);
            }

            // CB/PayPal : Stripe + frais de port en line item
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

            $opts = [
                'shipping_amount_cents' => $shippingAmountCents,
                'shipping_label' => sprintf(
                    'Frais de port — %s %s',
                    $postedCarrier,
                    $postedMethod
                ),
            ];

            $sessionStripe = $stripe->createCheckoutSession(
                $lineItems,
                $order->getReference(),
                $email,
                $opts
            );

            return $this->redirectToRoute('checkout_redirect', [
                'sessionId'  => $sessionStripe->id,
                'sessionUrl' => $sessionStripe->url,
            ]);

        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->addFlash('danger', 'Erreur lors de la validation du panier : ' . $e->getMessage());
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
