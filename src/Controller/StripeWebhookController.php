<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\ArticleRepository;
use App\Service\InvoiceService;
use App\Service\StripePaymentService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Webhook Stripe :
 * - Utilise metadata.order_ref ou client_reference_id
 * - Traite checkout.session.completed / async_payment_succeeded (+ fallback payment_intent.succeeded)
 * - Idempotent via order.isInvoiceSent()
 */
final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly StripePaymentService   $stripe,
        private readonly LoggerInterface        $logger,
        private readonly EntityManagerInterface $em,
        private readonly ArticleRepository      $articleRepo,
        private readonly MailerInterface        $mailer,
        private readonly InvoiceService         $invoiceService,
    ) {}

    #[Route('/webhook/stripe', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature') ?? $request->headers->get('stripe-signature', '');

        try {
            $event = $this->stripe->verifyWebhook($payload, $sigHeader);
        } catch (\Throwable $e) {
            $this->logger->error('[Stripe][Webhook] Signature/secret invalid', ['error' => $e->getMessage()]);
            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST, ['Content-Type' => 'text/plain']);
        }

        switch ($event->type) {
            case 'checkout.session.completed':
            case 'checkout.session.async_payment_succeeded': {
                /** @var \Stripe\Checkout\Session $session */
                $session = $event->data->object;

                $orderRef = null;
                if (isset($session->metadata) && isset($session->metadata['order_ref'])) {
                    $orderRef = $session->metadata['order_ref'];
                }
                if (!$orderRef && isset($session->client_reference_id)) {
                    $orderRef = (string) $session->client_reference_id;
                }

                $sessionId       = $session->id ?? null;
                $paymentStatus   = $session->payment_status ?? null;
                $paymentIntentId = $session->payment_intent ?? null;

                $customerEmail = $session->customer_email
                    ?? ($session->customer_details->email ?? null);

                $this->logger->debug('[Stripe][Webhook] checkout.session.*', [
                    'type'           => $event->type,
                    'order_ref'      => $orderRef,
                    'session_id'     => $sessionId,
                    'payment_status' => $paymentStatus,
                    'payment_intent' => $paymentIntentId,
                    'customer_email' => $customerEmail,
                ]);

                if (!$orderRef) {
                    return new Response('ignored_no_order_ref', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
                }
                if ($paymentStatus !== 'paid') {
                    return new Response('ignored_not_paid', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
                }

                $result = $this->processPaidOrder($orderRef, $sessionId, $paymentIntentId, $customerEmail);
                return new Response($result, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
            }

            case 'payment_intent.succeeded': {
                /** @var \Stripe\PaymentIntent $pi */
                $pi = $event->data->object;

                $orderRef        = isset($pi->metadata['order_ref']) ? (string) $pi->metadata['order_ref'] : null;
                $paymentIntentId = $pi->id ?? null;
                $customerEmail   = $pi->receipt_email
                    ?? ($pi->charges->data[0]->billing_details->email ?? null);

                $this->logger->debug('[Stripe][Webhook] payment_intent.succeeded', [
                    'order_ref'      => $orderRef,
                    'payment_intent' => $paymentIntentId,
                    'customer_email' => $customerEmail,
                ]);

                if (!$orderRef) {
                    return new Response('ignored_no_order_ref', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
                }

                $result = $this->processPaidOrder($orderRef, null, $paymentIntentId, $customerEmail);
                return new Response($result, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
            }

            case 'checkout.session.async_payment_failed':
            case 'payment_intent.payment_failed': {
                /** @var \Stripe\PaymentIntent|\Stripe\Checkout\Session $obj */
                $obj = $event->data->object;

                $orderRef = null;
                if (isset($obj->metadata['order_ref'])) {
                    $orderRef = (string) $obj->metadata['order_ref'];
                } elseif (isset($obj->client_reference_id)) {
                    $orderRef = (string) $obj->client_reference_id;
                }

                $failureCode    = $obj->last_payment_error->code        ?? null;
                $declineCode    = $obj->last_payment_error->decline_code ?? null;
                $failureMessage = $obj->last_payment_error->message     ?? 'unknown';

                $this->logger->warning('[Stripe][Webhook] payment_failed', [
                    'type'            => $event->type,
                    'order_ref'       => $orderRef,
                    'id'              => $obj->id ?? null,
                    'failure_code'    => $failureCode,
                    'decline_code'    => $declineCode,
                    'failure_message' => $failureMessage,
                ]);

                if ($orderRef) {
                    $order = $this->em->getRepository(Order::class)->findOneBy(['reference' => $orderRef]);
                    if ($order) {
                        if (enum_exists(OrderStatus::class) && \defined(OrderStatus::class.'::ECHEC')) {
                            $order->setStatus(OrderStatus::ECHEC);
                        }
                        if (method_exists($order, 'setPaymentFailedAt')) {
                            $order->setPaymentFailedAt(new \DateTimeImmutable());
                        }
                        $this->em->flush();
                    }
                }

                return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
            }

            default:
                $this->logger->debug('[Stripe][Webhook] Event received (ignored)', ['type' => $event->type]);
                return new Response('ok', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
        }
    }

    /**
     * Décrémente le stock, met la commande en EN_COURS, génère les factures.
     * Idempotent : si invoice déjà envoyée → stop.
     * On ne "marque facture envoyée" QU'APRÈS envoi de l'email client.
     */
    private function processPaidOrder(string $orderRef, ?string $sessionId, ?string $paymentIntentId, ?string $customerEmail): string
    {
        $order = $this->em->getRepository(Order::class)->findOneBy(['reference' => $orderRef]);
        if (!$order) {
            $this->logger->error('[Stripe][Webhook] Order not found for reference', ['order_ref' => $orderRef]);
            return 'order_not_found';
        }

        // Enregistrer les IDs Stripe si manquants (sans casser l’idempotence)
        $idsUpdated = false;
        if ($sessionId && method_exists($order, 'getStripeSessionId') && method_exists($order, 'setStripeSessionId') && !$order->getStripeSessionId()) {
            $order->setStripeSessionId($sessionId);
            $idsUpdated = true;
        }
        if ($paymentIntentId && method_exists($order, 'getStripePaymentIntentId') && method_exists($order, 'setStripePaymentIntentId') && !$order->getStripePaymentIntentId()) {
            $order->setStripePaymentIntentId($paymentIntentId);
            $idsUpdated = true;
        }
        if ($idsUpdated) {
            try {
                $this->em->flush();
            } catch (\Throwable $e) {
                $this->logger->warning('[Stripe][Webhook] Could not persist session/paymentIntent ids', [
                    'order_ref' => $orderRef,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        // Idempotence : si la facture a déjà été envoyée, on arrête
        if (method_exists($order, 'isInvoiceSent') && $order->isInvoiceSent()) {
            $this->logger->info('[Stripe][Webhook] Order already processed (invoice sent).', [
                'order_ref'      => $orderRef,
                'session_id'     => $sessionId,
                'payment_intent' => $paymentIntentId,
            ]);
            return 'already_processed';
        }

        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            // Décrément de stock
            foreach ($order->getItems() as $item) {
                $productId = method_exists($item, 'getProductId') ? $item->getProductId() : null;
                $qty       = (int) ($item->getQuantity() ?? 0);
                if (!$productId || $qty <= 0) {
                    continue;
                }

                $article = $this->articleRepo->find($productId);
                if (!$article) {
                    $this->logger->warning('[Stripe][Webhook] Article not found for item', [
                        'order_ref'  => $orderRef,
                        'product_id' => $productId,
                    ]);
                    continue;
                }

                $this->em->lock($article, LockMode::PESSIMISTIC_WRITE);
                $stock = (int) ($article->getQuantity() ?? 0);
                $article->setQuantity(max(0, $stock - $qty));
            }

            // Statut payé → EN_COURS (ou PAYEE si tu avais cette constante)
            if (enum_exists(OrderStatus::class)) {
                $order->setStatus(\defined(OrderStatus::class.'::PAYEE') ? OrderStatus::EN_COURS : OrderStatus::EN_COURS);
            }
            if (method_exists($order, 'setPaidAt')) {
                $order->setPaidAt(new \DateTimeImmutable());
            }

            // Génération PDF (client + vendeur)
            $clientPdfPath = $this->invoiceService->generate($order, false);
            $sellerPdfPath = $this->invoiceService->generate($order, true);

            $this->em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->logger->error('[Stripe][Webhook] Processing error', [
                'order_ref' => $orderRef,
                'error'     => $e->getMessage(),
            ]);
            return 'processing_error';
        }

        // ==== Envoi des emails (post-commit) ====
        $from     = (string) ($this->getParameter('app.contact_from') ?? 'no-reply@maisonvintage.test');
        $sellerTo = (string) ($this->getParameter('app.seller_email') ?? $from);
        $user     = $order->getUser();
        $clientTo = method_exists($user, 'getEmail') ? (string) $user->getEmail() : ($customerEmail ?: $from);

        // 1) Client : si OK → on *marque* la facture envoyée
        try {
            $emailClient = (new TemplatedEmail())
                ->from(new Address($from, 'Maison Vintage'))
                ->to($clientTo)
                ->subject('Confirmation de votre commande ' . $order->getReference())
                ->htmlTemplate('emails/order_confirmation.html.twig')
                ->context(['order' => $order, 'user' => $user])
                ->attachFromPath($clientPdfPath, sprintf('Facture-%s.pdf', $order->getReference()));
            $this->mailer->send($emailClient);

            if (method_exists($order, 'markInvoiceSent')) {
                $order->markInvoiceSent();
                $this->em->flush(); // on persiste l’état “envoyé” UNIQUEMENT si l’email client a bien été émis
            }
        } catch (\Throwable $mailErr) {
            $this->logger->error('[Stripe][Webhook] Client mail error (invoice NOT marked sent)', [
                'order_ref' => $orderRef,
                'error'     => $mailErr->getMessage(),
            ]);
            // on s’arrête là : on ne marque pas invoiceSent, pour pouvoir renvoyer depuis l’admin si besoin
            return 'processed_mail_client_failed';
        }

        // 2) Vendeur : échec non bloquant
        try {
            $emailSeller = (new TemplatedEmail())
                ->from(new Address($from, 'Maison Vintage'))
                ->to($sellerTo)
                ->subject('Copie facture — ' . $order->getReference())
                ->html('<p>Copie vendeur à archiver.</p>')
                ->attachFromPath($sellerPdfPath, sprintf('Facture-%s-copie.pdf', $order->getReference()));
            $this->mailer->send($emailSeller);
        } catch (\Throwable $mailErr) {
            $this->logger->warning('[Stripe][Webhook] Seller mail error (non-blocking)', [
                'order_ref' => $orderRef,
                'error'     => $mailErr->getMessage(),
            ]);
        }

        $this->logger->info('[Stripe][Webhook] Order processed: stock decremented, invoices generated, client mail sent.', [
            'order_ref'      => $orderRef,
            'session_id'     => $sessionId,
            'payment_intent' => $paymentIntentId,
        ]);

        return 'processed';
    }
}
