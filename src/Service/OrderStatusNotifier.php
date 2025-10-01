<?php

namespace App\Service;

use App\Entity\Order;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

class OrderStatusNotifier
{
    public function __construct(private MailerInterface $mailer) {}

    public function notify(Order $order): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($_ENV['CONTACT_FROM'] ?? 'no-reply@maisonvintage.test', 'MaisonVintage'))
            ->to($order->getUser()->getEmail())
            ->subject(sprintf('Votre commande %s est maintenant "%s"', $order->getReference(), $order->getStatus()->value))
            ->htmlTemplate('emails/order_status_update.html.twig')
            ->context([
                'order' => $order,
            ]);

        $this->mailer->send($email);
    }
}
