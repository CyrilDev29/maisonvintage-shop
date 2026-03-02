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
 * - Pour les virements : le stock avait été décrémenté à la création => RESTOCK.
 * - Pour CB/PayPal : pas de restock.
 *
 * Idempotent : ne touche que les commandes EN_ATTENTE_PAIEMENT expirées.
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule les changements (aucun flush).')
            ->addOption('card-minutes', null, InputOption::VALUE_REQUIRED, 'TTL CB/PayPal en minutes (fallback si reserved_until manquant)', '30')
            ->addOption('bank-hours',   null, InputOption::VALUE_REQUIRED, 'TTL virement en heures (fallback si reserved_until manquant)', '72')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Nombre max. de commandes à traiter', '200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $dryRun   = (bool) $input->getOption('dry-run');
        $now      = new \DateTimeImmutable('now');
        $limit    = (int)  $input->getOption('limit');
        $cardTTL  = max(1, (int) $input->getOption('card-minutes'));
        $bankTTL  = max(1, (int) $input->getOption('bank-hours'));

        // Ton repo peut déjà filtrer les expirées ; on garde ça,
        // mais on mettra un fallback ci-dessous si besoin.
        $expired = $this->orders->findExpiredPending($now);

        // Sécurité : limite de charge si le repo ne limite pas
        if (\is_array($expired) && $limit > 0 && \count($expired) > $limit) {
            $expired = \array_slice($expired, 0, $limit);
        }

        if (!$expired) {
            $io->success('Aucune commande expirée à libérer.');
            return Command::SUCCESS;
        }

        $io->section(sprintf('Commandes candidates : %d', \count($expired)));

        $released = 0;
        $restockedLines = 0;

        foreach ($expired as $order) {
            if (!$order instanceof Order) {
                continue;
            }
            // Sécurité : on n’agit que sur EN_ATTENTE_PAIEMENT
            if ($order->getStatus() !== OrderStatus::EN_ATTENTE_PAIEMENT) {
                continue;
            }

            // --- Fallback d’expiration si reserved_until absent ou incohérent ---
            $pm = \method_exists($order, 'getPaymentMethod') ? (string) ($order->getPaymentMethod() ?? '') : '';
            $reservedUntil = \method_exists($order, 'getReservedUntil') ? $order->getReservedUntil() : null;

            if (!$reservedUntil instanceof \DateTimeImmutable) {
                // on reconstruit une deadline à partir de createdAt + TTL
                $created  = $order->getCreatedAt() ?? new \DateTimeImmutable('-1 day');
                $ttlMin   = ($pm === 'bank_transfer') ? ($bankTTL * 60) : $cardTTL;
                $reservedUntil = $created->modify(sprintf('+%d minutes', $ttlMin));
            }

            if ($reservedUntil > $now) {
                // finalement pas expirée (le repo serait plus laxiste)
                continue;
            }
            // --------------------------------------------------------------------

            $this->em->getConnection()->beginTransaction();
            try {
                $isBankTransfer = ($pm === 'bank_transfer');

                if ($isBankTransfer) {
                    foreach ($order->getItems() as $item) {
                        $productId = \method_exists($item, 'getProductId') ? $item->getProductId() : null;
                        $qty       = (int) ($item->getQuantity() ?? 0);
                        if (!$productId || $qty <= 0) {
                            continue;
                        }

                        $article = $this->articles->find($productId);
                        if (!$article) {
                            continue;
                        }

                        $this->em->lock($article, LockMode::PESSIMISTIC_WRITE);
                        $article->setQuantity((int) $article->getQuantity() + $qty);
                        $this->em->persist($article);
                        $restockedLines++;
                    }
                }

                $order->setStatus(OrderStatus::ANNULEE);
                if (\method_exists($order, 'setCanceledAt')) {
                    $order->setCanceledAt(new \DateTimeImmutable());
                }

                if ($dryRun) {
                    $this->em->getConnection()->rollBack();
                } else {
                    $this->em->flush();
                    $this->em->getConnection()->commit();
                }

                $released++;
                $output->writeln(sprintf(
                    ' - %s [id:%s] annulée%s',
                    (string) $order->getReference(),
                    (string) $order->getId(),
                    $isBankTransfer ? ' + restock' : ''
                ));
            } catch (\Throwable $e) {
                if ($this->em->getConnection()->isTransactionActive()) {
                    $this->em->getConnection()->rollBack();
                }
                $io->warning(sprintf('Échec libération %s : %s', (string) $order->getReference(), $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Terminé : %d commande(s) annulée(s). Lignes restockées : %d. %s',
            $released,
            $restockedLines,
            $dryRun ? '[dry-run]' : ''
        ));

        return Command::SUCCESS;
    }
}
