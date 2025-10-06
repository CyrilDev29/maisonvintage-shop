<?php

namespace App\Controller\Account;

use App\Entity\Order;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
class InvoiceController extends AbstractController
{
    #[Route('/account/orders/{id}/invoice', name: 'account_order_invoice', methods: ['GET'])]
    public function download(Order $order): BinaryFileResponse
    {
        // Sécurité : l’utilisateur doit être propriétaire de la commande
        $this->denyAccessUnlessGranted('ROLE_USER');
        if ($order->getUser()?->getId() !== $this->getUser()?->getId()) {
            throw $this->createAccessDeniedException();
        }

        $year = $order->getCreatedAt()->format('Y');
        $path = sprintf('%s/var/invoices/%s/INV-%s-client.pdf',
            $this->getParameter('kernel.project_dir'),
            $year,
            $order->getReference()
        );

        if (!is_file($path)) {
            throw $this->createNotFoundException('Facture introuvable');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            sprintf('Facture-%s.pdf', $order->getReference())
        );

        return $response;
    }
}
