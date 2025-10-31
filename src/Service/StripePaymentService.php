<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session as StripeCheckoutSession;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Webhook;
use Stripe\Event;

/**
 * Service de gestion des paiements Stripe.
 *
 * - Crée les sessions Checkout.
 * - Vérifie les signatures de webhooks Stripe.
 * - Permet de relire une session Checkout pour afficher le récapitulatif.
 */
final class StripePaymentService
{
    private readonly StripeClient $client;

    public function __construct(
        private readonly string  $publicKey,
        private readonly string  $secretKey,
        private readonly string  $successUrl,
        private readonly string  $cancelUrl,
        private readonly string  $currency = 'EUR',
        private readonly bool    $enablePaypal = true,
        private readonly ?string $webhookSecret = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->client = new StripeClient($this->secretKey);
    }

    public function getPublicKey(): string
    {
        return $this->publicKey;
    }

    /**
     * Crée une session Stripe Checkout (mode "payment").
     *
     * @param array<int,array{name:string,unit_amount:int,quantity:int}> $items
     * @throws ApiErrorException
     */
    public function createCheckoutSession(array $items, string $orderReference, ?string $customerEmail = null): StripeCheckoutSession
    {
        $currency = \strtolower(\trim($this->currency)) ?: 'eur';
        $orderReference = \trim($orderReference);

        // Construction des line items avec validations minimales.
        $lineItems = [];
        foreach ($items as $item) {
            $name       = isset($item['name']) ? (string) $item['name'] : 'Article';
            $unitAmount = isset($item['unit_amount']) ? (int) $item['unit_amount'] : 0;
            $quantity   = isset($item['quantity']) ? (int) $item['quantity'] : 1;

            if ($unitAmount <= 0) {
                throw new \InvalidArgumentException('unit_amount must be a positive integer amount (in cents).');
            }
            if ($quantity < 1) {
                $quantity = 1;
            }

            $lineItems[] = [
                'price_data' => [
                    'currency'     => $currency,
                    'unit_amount'  => $unitAmount,
                    'product_data' => ['name' => $name],
                ],
                'quantity' => $quantity,
            ];
        }

        if ($lineItems === []) {
            throw new \InvalidArgumentException('At least one line item is required to create a Checkout Session.');
        }

        // Méthodes de paiement :
        // - 'card' est toujours présent
        // - 'paypal' : nécessite d'être activé côté compte Stripe ; sinon Stripe renverra une erreur à la création
        $paymentMethodTypes = ['card'];
        if ($this->enablePaypal) {
            $paymentMethodTypes[] = 'paypal';
        }

        $params = [
            'mode'                 => 'payment',
            'payment_method_types' => $paymentMethodTypes,
            'line_items'           => $lineItems,
            'success_url'          => $this->successUrl . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'           => $this->cancelUrl,
            'locale'               => 'fr', // interface Checkout en français

            // Traçabilité commande ↔ Stripe
            'client_reference_id'  => $orderReference,
            'metadata'             => ['order_ref' => $orderReference],
            'payment_intent_data'  => [
                'metadata' => ['order_ref' => $orderReference],
            ],
        ];

        if ($customerEmail) {
            $params['customer_email'] = $customerEmail;
        }

        $session = $this->client->checkout->sessions->create($params);

        $this->logger?->info('[Stripe][Checkout] Session created', [
            'session_id' => $session->id,
            'order_ref'  => $orderReference,
            'pm_types'   => $paymentMethodTypes,
        ]);

        return $session;
    }

    /**
     * Récupère une session Checkout.
     *
     * @throws ApiErrorException
     */
    public function retrieveCheckoutSession(string $sessionId): StripeCheckoutSession
    {
        return $this->client->checkout->sessions->retrieve($sessionId, [
            'expand' => ['payment_intent'],
        ]);
    }

    /**
     * Vérifie et décode un webhook Stripe en validant sa signature.
     *
     * @return Event
     * @throws \RuntimeException
     */
    public function verifyWebhook(string $payload, string $signatureHeader): Event
    {
        if (empty($this->webhookSecret)) {
            // Important : sans secret, on refuse le traitement pour éviter les faux positifs.
            throw new \RuntimeException('Stripe webhook secret not configured. Set STRIPE_WEBHOOK_SECRET.');
        }

        return Webhook::constructEvent($payload, $signatureHeader, $this->webhookSecret);
    }

    /**
     * Crée un remboursement Stripe sur un PaymentIntent.
     *
     * @param string      $paymentIntentId  ex: "pi_3SKcfnD6gqIbu1ZX0NWCwa6L"
     * @param int|null    $amount           Montant en CENTIMES (null = remboursement total)
     * @param string|null $reason           "requested_by_customer" | "duplicate" | "fraudulent" | null
     *
     * @return string     refund id ex: "re_3SKchzD6gqIbu1ZX0xY..."
     *
     * @throws ApiErrorException
     */
    public function refundPaymentIntent(string $paymentIntentId, ?int $amount = null, ?string $reason = 'requested_by_customer'): string
    {
        $params = [
            'payment_intent' => $paymentIntentId,
        ];

        if ($amount !== null && $amount > 0) {
            $params['amount'] = $amount; // en centimes
        }

        if ($reason !== null) {
            $params['reason'] = $reason;
        }

        $refund = $this->client->refunds->create($params);

        $this->logger?->info('[Stripe][Refund] Created', [
            'payment_intent' => $paymentIntentId,
            'refund_id'      => $refund->id,
            'amount'         => $refund->amount ?? null,
            'reason'         => $refund->reason ?? null,
        ]);

        return (string) $refund->id;
    }
}
