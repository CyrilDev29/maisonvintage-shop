<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\ArticleRepository;
use App\Repository\OrderRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Libère automatiquement les commandes "En attente de paiement" dont la réservation a expiré.
 * - Pour les virements : le stock avait été décrémenté à la création => on RESTOCK.
 * - Pour CB/PayPal : aucun décrément initial, donc pas de restock nécessaire.
 *
 * Idempotent : ne touche que les commandes EN_ATTENTE_PAIEMENT avec reservedUntil dépassé.
 */
#[AsCommand(
    name: 'app:orders:auto-release',
    description: 'Annule et libère les commandes expirées (deadline passée), avec restock si nécessaire.',
)]
final class AutoReleaseOrdersCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrderRepository        $orders,
        private readonly ArticleRepository      $articles,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // Permet de simuler sans rien écrire en base
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule les changements (aucun flush).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $now     = new \DateTimeImmutable('now');

        $expired = $this->orders->findExpiredPending($now);

        if (!$expired) {
            $io->success('Aucune commande expirée à libérer.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Commandes expirées trouvées : %d', \count($expired)));

        $released = 0;
        $restockedLines = 0;

        foreach ($expired as $order) {
            // Sécurité : on n’agit que sur EN_ATTENTE_PAIEMENT
            if ($order->getStatus() !== OrderStatus::EN_ATTENTE_PAIEMENT) {
                continue;
            }

            $this->em->getConnection()->beginTransaction();
            try {
                $pm = method_exists($order, 'getPaymentMethod') ? ($order->getPaymentMethod() ?? '') : '';
                $isBankTransfer = ($pm === 'bank_transfer');

                // Restock uniquement pour le virement (décrément fait au checkout)
                if ($isBankTransfer) {
                    foreach ($order->getItems() as $item) {
                        $productId = method_exists($item, 'getProductId') ? $item->getProductId() : null;
                        $qty       = (int) $item->getQuantity();

                        if (!$productId || $qty <= 0) {
                            continue;
                        }

                        $article = $this->articles->find($productId);
                        if (!$article) {
                            // L’article a pu être supprimé : on ignore la ligne.
                            continue;
                        }

                        // On verrouille pour éviter un conflit de concurrence.
                        $this->em->lock($article, LockMode::PESSIMISTIC_WRITE);

                        $current = (int) ($article->getQuantity() ?? 0);
                        $article->setQuantity($current + $qty);
                        $this->em->persist($article);
                        $restockedLines++;
                    }
                }

                // Marque l’ordre comme annulé et date l’annulation (si le champ existe).
                $order->setStatus(OrderStatus::ANNULEE);
                if (method_exists($order, 'setCanceledAt')) {
                    $order->setCanceledAt(new \DateTimeImmutable());
                }

                if (!$dryRun) {
                    $this->em->flush();
                    $this->em->getConnection()->commit();
                } else {
                    $this->em->getConnection()->rollBack();
                }

                $released++;
                $io->writeln(sprintf(' - %s [%s] libérée (%s)', $order->getReference(), $order->getId(), $pm ?: 'unknown'));
            } catch (\Throwable $e) {
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->getConnection()->rollBack();
                }
                // On passe à l’ordre suivant sans stopper la commande.
                $io->warning(sprintf('Echec libération %s : %s', $order->getReference(), $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Terminé : %d commande(s) libérée(s). Lignes restockées : %d. %s',
            $released,
            $restockedLines,
            $dryRun ? '[dry-run]' : ''
        ));

        return Command::SUCCESS;
    }
}
