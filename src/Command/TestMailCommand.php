<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(
    name: 'app:mail:test',
    description: 'Envoie un e-mail de test via Symfony Mailer pour vérifier la configuration.'
)]
final class TestMailCommand extends Command
{
    public function __construct(private readonly MailerInterface $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('to', InputArgument::REQUIRED, 'Adresse destinataire (ex : test@maisonvintage.test)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $to   = (string) $input->getArgument('to');
        $from = $_ENV['CONTACT_FROM'] ?? 'no-reply@maisonvintage.test';

        $io->title('Test d’envoi d’e-mail via Symfony Mailer');

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject('Test Mailer — Maison Vintage')
            ->text("Bonjour,\n\nCeci est un e-mail de test envoyé par Symfony Mailer.\n\n— Maison Vintage")
            ->html('<p>Bonjour,</p><p>Ceci est un <strong>e-mail de test</strong> envoyé par Symfony Mailer.</p><p>— Maison Vintage</p>');

        try {
            $this->mailer->send($email);
            $io->success(sprintf('✅ Mail envoyé à %s — vérifie MailHog : http://127.0.0.1:8025', $to));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error('❌ Échec de l’envoi : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
