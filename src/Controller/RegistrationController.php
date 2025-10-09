<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(private EmailVerifier $emailVerifier) {}

    #[Route('/register', name: 'app_register', methods: ['GET','POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $user = new User();

        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Hash + rôles + vérification e-mail
            $hashed = $hasher->hashPassword($user, $form->get('plainPassword')->getData());
            $user->setPassword($hashed);
            $user->setRoles(['ROLE_USER']);
            $user->setIsVerified(false);

            $em->persist($user);
            $em->flush();

            // Envoi de l’e-mail de confirmation
            $email = (new TemplatedEmail())
                ->from(new Address($_ENV['CONTACT_FROM'] ?? 'no-reply@maisonvintage.test', 'MaisonVintage'))
                ->to($user->getEmail())
                ->subject('Confirmez votre adresse e-mail')
                ->htmlTemplate('registration/confirmation_email.html.twig');

            $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user, $email);

            $this->addFlash('success', 'Un e-mail de confirmation vous a été envoyé. Veuillez vérifier votre boîte mail.');

            //  Turbo-friendly redirection explicite en 303
            return $this->redirectToRoute('app_login', [], Response::HTTP_SEE_OTHER);
        }

        // Turbo-friendly  422 si soumis mais invalide, 200 sinon
        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY   // 422
            : Response::HTTP_OK;                     // 200

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ], new Response(status: $status));
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, EntityManagerInterface $em): Response
    {
        $userId = $request->query->get('id');
        if (!$userId) {
            $this->addFlash('danger', 'Lien de vérification invalide.');
            return $this->redirectToRoute('app_login');
        }

        $user = $em->getRepository(User::class)->find($userId);
        if (!$user) {
            $this->addFlash('danger', 'Utilisateur introuvable.');
            return $this->redirectToRoute('app_login');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
            $em->flush();
            $this->addFlash('success', 'Votre e-mail a été vérifié avec succès. Vous pouvez vous connecter.');
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('danger', 'Le lien est invalide ou expiré : '.$e->getReason());
        }

        return $this->redirectToRoute('app_login');
    }

    #[Route('/verify/resend', name: 'app_verify_resend', methods: ['POST'])]
    public function resendVerificationEmail(
        Request $request,
        EntityManagerInterface $em,
        EmailVerifier $emailVerifier
    ): Response {
        $emailValue = (string) $request->request->get('email');
        if ($emailValue === '') {
            $this->addFlash('danger', 'Adresse e-mail manquante.');
            return $this->redirectToRoute('app_login');
        }

        /** @var User|null $user */
        $user = $em->getRepository(User::class)->findOneBy(['email' => $emailValue]);

        // Réponse générique pour ne pas divulguer l’existence d’un compte
        if (!$user) {
            $this->addFlash('success', 'Si un compte existe, un nouvel e-mail de vérification a été envoyé.');
            return $this->redirectToRoute('app_login');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Ce compte est déjà vérifié. Vous pouvez vous connecter.');
            return $this->redirectToRoute('app_login');
        }

        // Renvoi de l’e-mail de vérification
        $mail = (new TemplatedEmail())
            ->from(new Address($_ENV['CONTACT_FROM'] ?? 'no-reply@maisonvintage.test', 'MaisonVintage'))
            ->to($user->getEmail())
            ->subject('Confirmez votre adresse e-mail')
            ->htmlTemplate('registration/confirmation_email.html.twig');

        $emailVerifier->sendEmailConfirmation('app_verify_email', $user, $mail);

        $this->addFlash('success', 'Un nouvel e-mail de vérification a été envoyé. Merci de vérifier votre boîte mail.');
        return $this->redirectToRoute('app_login');
    }
}
