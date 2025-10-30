<?php

namespace App\Service;

use App\Entity\Order;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

class InvoiceService
{
    public function __construct(
        private readonly string $projectDir,
        private readonly Environment $twig,
    ) {}

    /**
     * Génère le PDF de facture et retourne le chemin absolu du fichier généré.
     * Chemin: var/invoices/{Y}/INV-{REF}-{client|seller}.pdf
     */
    public function generate(Order $order, bool $isSellerCopy = false): string
    {
        $html = $this->twig->render('invoice/invoice.html.twig', [
            'order'        => $order,
            'isSellerCopy' => $isSellerCopy,
            'invoice'      => [
                'seller' => [
                    'name'    => 'MaisonVintage',
                    'address' => '95 Kerizac, 29870 LANDEDA',
                    'siret'   => '752 102 897 00036',
                    'tvaMode' => 'FRANCHISE_293B',
                ],
            ],
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->setChroot($this->projectDir . '/public');
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dir = $this->projectDir . '/var/invoices/' . $order->getCreatedAt()->format('Y');
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Impossible de créer le dossier de factures: %s', $dir));
            }
        }

        $filename = sprintf(
            'INV-%s%s.pdf',
            $order->getReference(),
            $isSellerCopy ? '-seller' : '-client'
        );

        $path = $dir . '/' . $filename;

        $pdfBinary = $dompdf->output();
        if (@file_put_contents($path, $pdfBinary) === false) {
            throw new \RuntimeException(sprintf('Écriture du PDF impossible: %s', $path));
        }

        return $path;
    }
}
