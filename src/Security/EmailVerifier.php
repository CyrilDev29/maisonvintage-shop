<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mailer\MailerInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;
use Symfony\Component\HttpFoundation\Request;

class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $helper,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $router
    ) {}

    public function sendEmailConfirmation(string $routeName, User $user, TemplatedEmail $email): void
    {
        $signature = $this->helper->generateSignature(
            $routeName,
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => $user->getId()]
        );

        // Injecte les variables dans le template
        $email->context([
            'user' => $user,
            'signedUrl' => $signature->getSignedUrl(),
            'expiresAtMessageKey' => $signature->getExpirationMessageKey(),
            'expiresAtMessageData' => $signature->getExpirationMessageData(),
        ]);

        $this->mailer->send($email);
    }

    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->helper->validateEmailConfirmation(
            $request->getUri(),
            (string) $user->getId(),
            $user->getEmail()
        );
        $user->setIsVerified(true);
    }
}
