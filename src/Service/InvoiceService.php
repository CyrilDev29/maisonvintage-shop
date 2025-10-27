<?php

namespace App\Service;

use App\Entity\Order;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class InvoiceService extends AbstractController
{
    public function __construct(private string $projectDir) {}

    /**
     * Génère le PDF de facture et retourne le chemin absolu du fichier généré.
     * - Conserve la convention: var/invoices/{Y}/INV-{REF}-{client|seller}.pdf
     * - Active les assets distants (logos, CSS) et limite l'accès aux fichiers au répertoire public/
     */
    public function generate(Order $order, bool $isSellerCopy = false): string
    {
        $html = $this->renderView('invoice/invoice.html.twig', [
            'order'        => $order,
            'isSellerCopy' => $isSellerCopy,
            'invoice'      => [
                'seller' => [
                    'name'    => 'MaisonVintage',
                    'address' => 'ST ANTOINE, 1 AN ODE BRI, 29870 LANDEDA',
                    'siret'   => '752 102 897 00036',
                    'tvaMode' => 'FRANCHISE_293B',
                ],
            ],
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', true);                         // autorise logos / CSS distants
        $options->setChroot($this->projectDir . '/public');             // sécurité: accès fichier limité à /public
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dir = $this->projectDir . '/var/invoices/' . $order->getCreatedAt()->format('Y') . '/';
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

        $path = $dir . $filename;

        // Dompdf::output() retourne la chaîne binaire du PDF
        $pdfBinary = $dompdf->output();
        if (@file_put_contents($path, $pdfBinary) === false) {
            throw new \RuntimeException(sprintf('Écriture du PDF impossible: %s', $path));
        }

        return $path;
    }
}
