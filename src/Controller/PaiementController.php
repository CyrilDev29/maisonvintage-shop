<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
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

final class PaiementController extends AbstractController
{
    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly EntityManagerInterface $em,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Page de succès définie dans PAYMENT_SUCCESS_URL (.env*)
     * On retrouve la commande via la metadata "order_ref" stockée dans la session Stripe.
     * Amélioration : on vide le panier si Stripe confirme "paid".
     */
    #[Route('/paiement/success', name: 'paiement_success', methods: ['GET'])]
    public function success(Request $request, SessionInterface $sessionStorage): Response
    {
        $sessionId = (string) $request->query->get('session_id', '');
        $order = null;

        if ($sessionId !== '') {
            try {
                $session = $this->stripe->retrieveCheckoutSession($sessionId);

                // 1) vider le panier si paiement confirmé (effet immédiat sur le badge)
                if (($session->payment_status ?? null) === 'paid') {
                    $sessionStorage->remove('cart'); // idempotent, ne casse rien si déjà vide
                }

                // 2) retrouver la commande pour l'afficher sur la page succès (facultatif)
                $orderRef = $session->metadata->order_ref ?? null;
                if ($orderRef) {
                    $order = $this->em->getRepository(Order::class)
                        ->findOneBy(['reference' => $orderRef]);
                }
            } catch (\Throwable $e) {
                // On n’échoue pas la page : on affiche simplement le bandeau de réussite
            }
        }

        return $this->render('paiement/success.html.twig', [
            'sessionId' => $sessionId,
            'order'     => $order,
        ]);
    }

    /**
     * Page d’annulation (inchangée, utile si l’utilisateur annule côté Stripe).
     */
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
     * Téléchargement / affichage de la facture PDF de la commande.
     * On régénère le PDF à la volée (chemin transient) pour être sûr qu’il existe.
     */
    #[Route('/commande/{id}/facture', name: 'order_invoice_pdf', methods: ['GET'])]
    public function invoice(Order $order): Response
    {
        // Sécurité simple : la facture de cette commande ne peut être vue que par son propriétaire
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        if ($order->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // Regénère (ou génère) la facture côté client.
        $pdfPath = $this->invoiceService->generate($order, false);

        $response = new BinaryFileResponse($pdfPath);
        $response->headers->set('Content-Type', 'application/pdf');
        // "inline" pour ouvrir dans le navigateur ; mettre "attachment" si tu veux forcer le téléchargement
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            sprintf('Facture-%s.pdf', $order->getReference())
        );

        return $response;
    }
}
