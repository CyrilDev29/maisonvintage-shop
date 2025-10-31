<?php

namespace App\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\ArticleRepository;
use App\Service\StripePaymentService;
use App\Service\InvoiceService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;

#[Route('/account/orders')]
class OrderController extends AbstractController
{
    #[Route('/{id}', name: 'app_account_order_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(Order $order): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($order->getUser() !== $user) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à cette commande.');
        }

        return $this->render('account/order_show.html.twig', [
            'order' => $order,
            'items' => $order->getItems(),
        ]);
    }

    /**
     * Téléchargement de la facture client (PDF).
     *
     * IMPORTANT :
     * - La facture n'est disponible qu'après validation effective du paiement
     *   (ex. statut >= EN_COURS : CB/PayPal validés par webhook, virement validé manuellement).
     * - On bloque explicitement pour les statuts d'attente (ex. EN_ATTENTE_PAIEMENT).
     */
    #[Route('/{id}/invoice', name: 'account_order_invoice', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function downloadInvoice(Order $order, InvoiceService $invoiceService): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($order->getUser() !== $user) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à cette facture.');
        }

        // Refus systématique si commande annulée
        if ($order->getStatus() === OrderStatus::ANNULEE) {
            $this->addFlash('warning', 'Cette commande est annulée : la facture n’est plus disponible.');
            return $this->redirectToRoute('app_account_order_show', ['id' => $order->getId()]);
        }

        // Refus tant que le paiement n’est pas validé (virement en attente, etc.)
        // Adapte la liste si ton enum contient d’autres statuts "en attente".
        $waitingStatuses = [
            OrderStatus::EN_ATTENTE_PAIEMENT, // affiché dans ton e-mail ("En attente de paiement")
            // OrderStatus::EN_ATTENTE_VIREMENT, // décommente si tu as un statut dédié
        ];
        if (in_array($order->getStatus(), $waitingStatuses, true)) {
            $this->addFlash('info', 'La facture sera disponible après validation du paiement (virement reçu).');
            return $this->redirectToRoute('app_account_order_show', ['id' => $order->getId()]);
        }

        // À ce stade, le paiement est validé : génération si besoin + téléchargement
        $pdfPath = $invoiceService->generate($order, false);
        $downloadName = sprintf('Facture-%s.pdf', $order->getReference());

        return $this->file($pdfPath, $downloadName, ResponseHeaderBag::DISPOSITION_ATTACHMENT);
    }

    #[Route('/{id}/cancel', name: 'app_account_order_cancel', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(
        Order $order,
        Request $request,
        EntityManagerInterface $em,
        ArticleRepository $articleRepo,
        MailerInterface $mailer,
        ParameterBagInterface $params,
        StripePaymentService $stripe
    ): RedirectResponse {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($order->getUser() !== $user) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à cette commande.');
        }

        if (!$this->isCsrfTokenValid('cancel_order_'.$order->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Action non autorisée. Veuillez réessayer.');
            return $this->redirectToRoute('app_account_order_show', ['id' => $order->getId()]);
        }

        // États non annulables
        $nonCancellable = [
            OrderStatus::EXPEDIEE,
            OrderStatus::LIVREE,
            OrderStatus::ANNULEE,
        ];
        if (\in_array($order->getStatus(), $nonCancellable, true)) {
            $this->addFlash('warning', 'Cette commande ne peut pas être annulée à ce stade.');
            return $this->redirectToRoute('app_account_order_show', ['id' => $order->getId()]);
        }

        // Commande considérée "traitée/payée" si le webhook est passé :
        // - statut au moins EN_COURS (ou étapes suivantes)
        // - OU invoice_sent déjà positionné (idempotence côté webhook)
        $isProcessedPaid =
            \in_array($order->getStatus(), [
                OrderStatus::EN_COURS,
                OrderStatus::EN_PREPARATION,
                OrderStatus::EXPEDIEE,
                OrderStatus::LIVREE,
            ], true)
            || (method_exists($order, 'isInvoiceSent') && $order->isInvoiceSent());

        $conn = $em->getConnection();
        $conn->beginTransaction();

        try {
            // Rétablissement du stock UNIQUEMENT si la commande a été effectivement traitée/payée.
            if ($isProcessedPaid) {
                foreach ($order->getItems() as $item) {
                    $productId = method_exists($item, 'getProductId') ? $item->getProductId() : null;
                    $qty       = (int) $item->getQuantity();
                    if (!$productId || $qty <= 0) {
                        continue;
                    }

                    $article = $articleRepo->find($productId);
                    if (!$article) {
                        continue;
                    }

                    // Pessimistic write pour éviter les courses avec d'autres opérations.
                    $em->lock($article, LockMode::PESSIMISTIC_WRITE);
                    $current = (int) ($article->getQuantity() ?? 0);
                    $article->setQuantity($current + $qty);
                }
            }

            // Statut et horodatage d’annulation
            $order->setStatus(OrderStatus::ANNULEE);
            if (method_exists($order, 'setCanceledAt')) {
                $order->setCanceledAt(new \DateTimeImmutable());
            }

            $em->flush();
            $conn->commit();

            $this->addFlash('success', $isProcessedPaid
                ? 'Commande annulée, stock rétabli.'
                : 'Commande annulée.');
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->addFlash('danger', 'Erreur lors de l’annulation : '.$e->getMessage());
            return $this->redirectToRoute('app_account_order_show', ['id' => $order->getId()]);
        }

        // Remboursement Stripe (post-commit). On ne le tente QUE si la commande a été traitée/payée.
        $refundedNow = false;
        if ($isProcessedPaid && method_exists($order, 'getStripePaymentIntentId') && $order->getStripePaymentIntentId()) {
            $piId = $order->getStripePaymentIntentId();

            // Évite le double remboursement si déjà effectué.
            $alreadyRefunded = method_exists($order, 'getStripeRefundId') && $order->getStripeRefundId();
            if (!$alreadyRefunded) {
                try {
                    $refundId = $stripe->refundPaymentIntent($piId, null, 'requested_by_customer');

                    $changed = false;
                    if (method_exists($order, 'setStripeRefundId')) {
                        $order->setStripeRefundId($refundId);
                        $changed = true;
                    }
                    if (method_exists($order, 'setRefundedAt')) {
                        $order->setRefundedAt(new \DateTimeImmutable());
                        $changed = true;
                    }
                    if ($changed) {
                        $em->flush();
                    }

                    $refundedNow = true;
                    $this->addFlash('success', 'Le paiement Stripe a été remboursé.');
                } catch (\Throwable $refundErr) {
                    // L'annulation est effective, mais le refund a échoué : on l'indique clairement.
                    $this->addFlash('warning', 'Commande annulée, mais le remboursement Stripe a échoué : '.$refundErr->getMessage());
                }
            }
        } else {
            // Information claire : annulée, mais pas de refund tenté car pas de paiement confirmé connu.
            // (ex: annulation avant passage du webhook ou commande jamais payée)
            $this->addFlash('info', 'Commande annulée. Aucun remboursement automatique n’a été déclenché.');
        }

        // Email d’alerte à la vendeuse (post-commit)
        try {
            $from      = (string) ($params->get('app.contact_from') ?? 'no-reply@maisonvintage.test');
            $sellerTo  = (string) ($params->get('app.seller_email') ?? $from);

            $email = (new TemplatedEmail())
                ->from(new Address($from, 'Maison Vintage'))
                ->to($sellerTo)
                ->subject(sprintf('Annulation commande %s%s', $order->getReference(), $refundedNow ? ' (remboursée)' : ''))
                ->htmlTemplate('emails/order_canceled.html.twig')
                ->context([
                    'order'        => $order,
                    'user'         => $user,
                    'refundedNow'  => $refundedNow,
                ]);

            $mailer->send($email);
        } catch (\Throwable $mailErr) {
            // On n'interrompt pas l'expérience utilisateur si l'email échoue.
        }

        return $this->redirectToRoute('app_account_order_show', ['id' => $order->getId()]);
    }
}
