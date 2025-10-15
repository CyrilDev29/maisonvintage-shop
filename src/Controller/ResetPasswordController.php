<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordToken;

class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $em
    ) {}

    #[Route('/reset-password', name: 'app_forgot_password_request', methods: ['GET','POST'])]
    public function request(Request $request, UserRepository $users, MailerInterface $mailer): Response
    {
        if ($request->isMethod('POST')) {
            // Vérifie le CSRF
            $csrf = (string) $request->request->get('_token', '');
            if (!$this->isCsrfTokenValid('forgot_password_request', $csrf)) {
                $this->addFlash('danger', 'Action refusée (sécurité).');
                return $this->redirectToRoute('app_forgot_password_request');
            }

            $email = trim((string) $request->request->get('email', ''));
            if ($email === '') {
                $this->addFlash('danger', 'Veuillez renseigner votre e-mail.');
                return $this->redirectToRoute('app_forgot_password_request');
            }

            /** @var User|null $user */
            $user = $users->findOneBy(['email' => $email]);

            // Réponse neutre : ne divulgue pas l'existence du compte
            if ($user) {
                try {
                    $resetToken = $this->resetPasswordHelper->generateResetToken($user);

                    $message = (new TemplatedEmail())
                        ->from(new Address($_ENV['CONTACT_FROM'] ?? 'no-reply@maisonvintage.fr', 'Maison Vintage'))
                        ->to($user->getEmail())
                        ->subject('Réinitialisation de votre mot de passe')
                        ->htmlTemplate('reset_password/email.html.twig')
                        ->context(['resetToken' => $resetToken]);

                    $mailer->send($message);
                    $this->setTokenObjectInSession($resetToken);
                } catch (ResetPasswordExceptionInterface $e) {
                    // En cas d’erreur (ex : table vide, throttle…), on ne dit rien à l’utilisateur
                }
            }

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig');
    }

    #[Route('/reset-password/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        /** @var ResetPasswordToken|null $token */
        $token = $this->getTokenObjectFromSession();

        return $this->render('reset_password/check_email.html.twig', [
            'tokenLifetime' => $token?->getExpirationMessageData()['lifetime'] ?? null,
        ]);
    }

    #[Route('/reset-password/reset/{token}', name: 'app_reset_password', methods: ['GET','POST'])]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $hasher,
        string $token = null
    ): Response {
        if ($token) {
            $this->storeTokenInSession($token);
            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            $this->addFlash('danger', 'Le lien de réinitialisation est invalide ou expiré.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('danger', 'Le lien de réinitialisation est invalide ou expiré.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        if ($request->isMethod('POST')) {
            $plain   = (string) $request->request->get('plainPassword', '');
            $confirm = (string) $request->request->get('confirmPassword', '');

            if ($plain === '' || $plain !== $confirm) {
                $this->addFlash('danger', 'Les mots de passe ne correspondent pas.');
                return $this->redirectToRoute('app_reset_password');
            }

            $this->resetPasswordHelper->removeResetRequest($token);

            $user->setPassword($hasher->hashPassword($user, $plain));
            $this->em->flush();

            $this->cleanSessionAfterReset();

            $this->addFlash('success', 'Votre mot de passe a été réinitialisé.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig');
    }
}
