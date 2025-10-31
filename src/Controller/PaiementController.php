<?php
// src/Controller/PaiementController.php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Service\InvoiceService;
use App\Service\StripePaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Gestion post-paiement :
 * - Success / Cancel Stripe (UX),
 * - Page d’instructions virement,
 * - Téléchargement de la facture.
 */
final class PaiementController extends AbstractController
{
    public function __construct(
        private readonly StripePaymentService   $stripe,
        private readonly EntityManagerInterface $em,
        private readonly InvoiceService         $invoiceService,
    ) {}

    #[Route('/paiement/success', name: 'paiement_success', methods: ['GET'])]
    public function success(Request $request, SessionInterface $sessionStorage): Response
    {
        $sessionId = (string) $request->query->get('session_id', '');
        $order = null;

        if ($sessionId !== '') {
            try {
                $session = $this->stripe->retrieveCheckoutSession($sessionId);

                if (($session->payment_status ?? null) === 'paid') {
                    // Le webhook réalise le traitement métier. Ici on vide juste le panier côté UX.
                    $sessionStorage->remove('cart');
                }

                $orderRef = $session->metadata->order_ref ?? null;
                if ($orderRef) {
                    $order = $this->em->getRepository(Order::class)
                        ->findOneBy(['reference' => $orderRef]);
                }
            } catch (\Throwable) {
                // Tolérant à l’erreur pour ne pas bloquer l’affichage.
            }
        }

        return $this->render('paiement/success.html.twig', [
            'sessionId' => $sessionId,
            'order'     => $order,
        ]);
    }

    #[Route('/paiement/cancel', name: 'paiement_cancel', methods: ['GET'])]
    public function cancel(): Response
    {
        $html = <<<HTML
<!DOCTYPE html><html lang="fr"><meta charset="utf-8">
<title>Paiement annulé</title>
<body style="font-family:Arial,sans-serif;padding:2rem">
<h1 style="margin:0 0 1rem">Paiement annulé</h1>
<p>Le paiement a été annulé. Aucun débit ne sera effectué.</p>
<p>Vous pouvez réessayer depuis votre panier.</p>
</body></html>
HTML;
        return new Response($html);
    }

    /**
     * Ancienne route de facture (laisser en place si des liens historiques existent).
     * NB : l'accès réel à la facture est déjà sécurisé dans OrderController::downloadInvoice().
     */
    #[Route('/commande/{id}/facture', name: 'order_invoice_pdf', methods: ['GET'])]
    public function invoice(Order $order): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        if ($order->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $pdfPath = $this->invoiceService->generate($order, false);

        $response = new BinaryFileResponse($pdfPath);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            sprintf('Facture-%s.pdf', $order->getReference())
        );

        return $response;
    }

    /**
     * Page d’instructions virement :
     * - Laisse/positionne la commande en EN_ATTENTE_PAIEMENT.
     * - Affiche le récapitulatif + RIB.
     *
     * IMPORTANT : l'e-mail d'instructions virement est envoyé UNE SEULE FOIS
     * dans CheckoutController::confirm() (pour éviter les doublons).
     */
    #[Route('/paiement/virement', name: 'paiement_bank_transfer', methods: ['GET'])]
    public function bankTransfer(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $id  = (int) $request->query->get('id');
        $ref = (string) $request->query->get('ref', '');

        $order = $this->em->getRepository(Order::class)->find($id);
        if (!$order || $order->getReference() !== $ref) {
            throw $this->createNotFoundException('Commande introuvable.');
        }
        if ($order->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // Assure l’état "en attente de paiement" (idempotent).
        try {
            if ($order->getStatus() !== OrderStatus::EN_COURS) {
                $order->setStatus(OrderStatus::EN_ATTENTE_PAIEMENT);
                $this->em->flush();
            }
        } catch (\Throwable) {
            // On n'interrompt pas l'affichage si le flush échoue.
        }

        // Affiche le récapitulatif + coordonnées bancaires (plus d'envoi d'e-mail ici).
        return $this->render('paiement/bank_transfer.html.twig', [
            'order'         => $order,
            'bank_holder'   => (string) ($this->getParameter('bank.holder')   ?? ''),
            'bank_bankname' => (string) ($this->getParameter('bank.bankname') ?? ''),
            'bank_iban'     => (string) ($this->getParameter('bank.iban')     ?? ''),
            'bank_bic'      => (string) ($this->getParameter('bank.bic')      ?? ''),
        ]);
    }
}
