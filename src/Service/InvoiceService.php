<?php

namespace App\Service;

use App\Entity\Order;
use Dompdf\Dompdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class InvoiceService extends AbstractController
{
    public function __construct(private string $projectDir) {}

    /** Génère le PDF et retourne son chemin absolu */
    public function generate(Order $order, bool $isSellerCopy = false): string
    {
        $html = $this->renderView('invoice/invoice.html.twig', [
            'order' => $order,
            'isSellerCopy' => $isSellerCopy,
            'invoice' => [
                'seller' => [
                    'name' => 'MaisonVintage',
                    'address' => 'ST ANTOINE, 1 AN ODE BRI, 29870 LANDEDA',
                    'siret' => '752 102 897 00036',
                    'tvaMode' => 'FRANCHISE_293B',
                ],
            ],
        ]);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dir = $this->projectDir . '/var/invoices/' . $order->getCreatedAt()->format('Y') . '/';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

        $filename = sprintf('INV-%s%s.pdf',
            $order->getReference(),
            $isSellerCopy ? '-seller' : '-client'
        );

        $path = $dir . $filename;
        file_put_contents($path, $dompdf->output());

        return $path;
    }
}
