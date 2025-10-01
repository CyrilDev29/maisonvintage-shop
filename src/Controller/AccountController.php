<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Order;
use App\Form\Account\ProfileFormType;
use App\Form\Account\ChangePasswordFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/account')]
class AccountController extends AbstractController
{
    #[Route('', name: 'app_account')]
    public function index(): Response
    {
        // Page "hub" (liens vers infos, mot de passe, commandes)
        return $this->render('account/index.html.twig');
    }

    #[Route('/profile', name: 'app_account_profile')]
    public function profile(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ProfileFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Vos informations ont bien été mises à jour.');
            return $this->redirectToRoute('app_account_profile');
        }

        return $this->render('account/profile.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/password', name: 'app_account_password')]
    public function password(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $new = (string) $form->get('plainPassword')->getData();
            $user->setPassword($hasher->hashPassword($user, $new));
            $em->flush();
            $this->addFlash('success', 'Votre mot de passe a été changé.');
            return $this->redirectToRoute('app_account_password');
        }

        return $this->render('account/password.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/orders', name: 'app_account_orders')]
    public function orders(EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Nécessite App\Entity\Order + méthode repo findByUserOrdered(User)
        $orders = $em->getRepository(Order::class)->findByUserOrdered($user);

        return $this->render('account/orders.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/orders/{id}', name: 'app_account_order_show', requirements: ['id' => '\d+'])]
    public function orderShow(Order $order): Response
    {
        // Empêche d’ouvrir la commande d’un autre utilisateur
        if ($order->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('account/order_show.html.twig', [
            'order' => $order,
        ]);
    }
}
