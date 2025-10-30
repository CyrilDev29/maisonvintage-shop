<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Service responsable de l'envoi des e-mails lors d'un changement de statut de commande.
 *
 * - Le paramètre d'expéditeur est injecté via le paramètre de config "app.contact_from".
 * - Chaque tentative d'envoi est tracée dans les logs applicatifs.
 * - Une erreur transport n'interrompt pas le flux appelant (le paiement/statut ne doit pas échouer à cause du mail).
 */
final class OrderStatusNotifier
{
    private string $fromEmail;
    private string $fromName = 'MaisonVintage';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire(param: 'app.contact_from')] string $contactFrom
    ) {
        // Valeur de repli si le paramètre n'est pas défini
        $this->fromEmail = $contactFrom !== '' ? $contactFrom : 'no-reply@maisonvintage.test';
    }

    /**
     * Envoie un e-mail au client lorsque le statut de sa commande évolue.
     */
    public function notify(Order $order): void
    {
        $this->logger->info('[OrderStatusNotifier] Préparation e-mail statut commande', [
            'order_id'   => $order->getId(),
            'reference'  => $order->getReference(),
            'new_status' => $order->getStatus()?->value,
            'to'         => $order->getUser()?->getEmail(),
        ]);

        $email = (new TemplatedEmail())
            ->from(new Address($this->fromEmail, $this->fromName))
            ->to($order->getUser()->getEmail())
            ->subject(sprintf(
                'Commande %s — statut : %s',
                $order->getReference(),
                $order->getStatus()?->value ?? 'Non défini'
            ))
            ->htmlTemplate('emails/order_status_update.html.twig')
            ->context([
                'order' => $order,
            ]);

        try {
            $this->mailer->send($email);
            $this->logger->info('[OrderStatusNotifier] E-mail envoyé', [
                'reference'  => $order->getReference(),
                'new_status' => $order->getStatus()?->value,
            ]);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('[OrderStatusNotifier] Echec d\'envoi e-mail', [
                'reference'  => $order->getReference(),
                'new_status' => $order->getStatus()?->value,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
