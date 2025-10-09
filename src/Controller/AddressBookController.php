<?php

namespace App\Controller;

use App\Entity\Address;
use App\Form\AddressType;
use App\Repository\AddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/account/address')]
class AddressBookController extends AbstractController
{
    #[Route('', name: 'account_address_index', methods: ['GET'])]
    public function index(AddressRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $addresses = $repo->findBy(['user' => $this->getUser()], ['createdAt' => 'DESC']);

        return $this->render('account/address_index.html.twig', [
            'addresses' => $addresses,
        ]);
    }

    #[Route('/new', name: 'account_address_new', methods: ['GET','POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $address = (new Address())->setUser($this->getUser());
        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($address);
            $em->flush();
            $this->addFlash('success', 'Adresse ajoutée.');
            return $this->redirectToRoute('account_address_index');
        }

        return $this->render('account/address_new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'account_address_edit', methods: ['GET','POST'])]
    public function edit(Address $address, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // sécurité: l'adresse doit appartenir à l'utilisateur
        if ($address->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Adresse mise à jour.');
            // si on vient du checkout, on y retourne
            $back = $request->query->get('back');
            return $this->redirectToRoute($back === 'checkout' ? 'checkout_address' : 'account_address_index');
        }

        return $this->render('account/address_edit.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'account_address_delete', methods: ['POST'])]
    public function delete(Address $address, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if ($address->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($this->isCsrfTokenValid('del_addr_'.$address->getId(), (string)$request->request->get('_token'))) {
            $em->remove($address);
            $em->flush();

            // Si on avait sélectionné cette adresse pour le checkout, on nettoie la session
            $sess = $request->getSession();
            if ((int)$sess->get('checkout.address_id') === (int)$address->getId()) {
                $sess->remove('checkout.address_id');
            }
            if ((int)$sess->get('checkout.billing_address_id') === (int)$address->getId()) {
                $sess->remove('checkout.billing_address_id');
            }

            $this->addFlash('success', 'Adresse supprimée.');
        } else {
            $this->addFlash('danger', 'Action refusée.');
        }

        $back = $request->query->get('back');
        return $this->redirectToRoute($back === 'checkout' ? 'checkout_address' : 'account_address_index');
    }
}
