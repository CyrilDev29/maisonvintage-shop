<?php

namespace App\Controller;

use App\Entity\Address;
use App\Form\AddressType;
use App\Repository\AddressRepository;
use App\Service\AddressBookService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CheckoutAddressController extends AbstractController
{
    #[Route('/checkout/address', name: 'checkout_address', methods: ['GET','POST'])]
    public function address(
        Request $request,
        AddressRepository $addressRepo,
        EntityManagerInterface $em,
        AddressBookService $addressBook
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        $user = $this->getUser();
        $session = $request->getSession();

        // 1) si aucune adresse, tenter d’en créer une à partir du profil
        $addressBook->ensurePrimaryAddress($user);

        // 2) liste des adresses existantes
        $addresses = $addressRepo->findBy(['user' => $user], ['createdAt' => 'DESC']);

        // 3) formulaire d’ajout (VIDE volontairement)
        $newAddress = (new Address())->setUser($user);
        $form = $this->createForm(AddressType::class, $newAddress);
        $form->handleRequest($request);

        // === ACTIONS POST ===
        // a) Sélection d’une adresse de LIVRAISON
        if ($request->isMethod('POST') && $request->request->get('action') === 'select_shipping') {
            $token = (string) $request->request->get('_token');
            if (!$this->isCsrfTokenValid('checkout_address_select_shipping', $token)) {
                $this->addFlash('danger', 'Sécurité: action refusée (token invalide).');
                return $this->redirectToRoute('checkout_address');
            }

            $selectedId = (int) $request->request->get('selected_address_id', 0);
            if ($selectedId > 0) {
                $selected = $addressRepo->findOneBy(['id' => $selectedId, 'user' => $user]);
                if ($selected) {
                    $session->set('checkout.address_id', $selected->getId());
                    // si facturation = même que livraison, pas besoin de conserver un id billing
                    if ($session->get('checkout.billing_same', true)) {
                        $session->remove('checkout.billing_address_id');
                    }
                    $this->addFlash('success', 'Adresse de livraison sélectionnée.');
                    return $this->redirectToRoute('checkout');
                }
            }
            $this->addFlash('danger', 'Adresse introuvable.');
            return $this->redirectToRoute('checkout_address');
        }

        // b) Bascule "facturation = même que livraison"
        if ($request->isMethod('POST') && $request->request->get('action') === 'billing_same_toggle') {
            $token = (string) $request->request->get('_token');
            if (!$this->isCsrfTokenValid('checkout_address_billing_same_toggle', $token)) {
                $this->addFlash('danger', 'Sécurité: action refusée (token invalide).');
                return $this->redirectToRoute('checkout_address');
            }

            $same = (bool) $request->request->get('billing_same', false);
            $session->set('checkout.billing_same', $same);
            if ($same) {
                $session->remove('checkout.billing_address_id');
            }
            return $this->redirectToRoute('checkout_address');
        }

        // c) Sélection d’une adresse de FACTURATION (si différente)
        if ($request->isMethod('POST') && $request->request->get('action') === 'select_billing') {
            $token = (string) $request->request->get('_token');
            if (!$this->isCsrfTokenValid('checkout_address_select_billing', $token)) {
                $this->addFlash('danger', 'Sécurité: action refusée (token invalide).');
                return $this->redirectToRoute('checkout_address');
            }

            $billingId = (int) $request->request->get('billing_address_id', 0);
            if ($billingId > 0) {
                $billing = $addressRepo->findOneBy(['id' => $billingId, 'user' => $user]);
                if ($billing) {
                    $session->set('checkout.billing_same', false);
                    $session->set('checkout.billing_address_id', $billing->getId());
                    $this->addFlash('success', 'Adresse de facturation sélectionnée.');
                    return $this->redirectToRoute('checkout');
                }
            }
            $this->addFlash('danger', 'Adresse de facturation introuvable.');
            return $this->redirectToRoute('checkout_address');
        }

        // d) Création d’une nouvelle adresse (protégée nativement par la CSRF des Form Symfony)
        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($newAddress);
            $em->flush();
            // on sélectionne automatiquement la nouvelle adresse pour la livraison
            $session->set('checkout.address_id', $newAddress->getId());
            if ($session->get('checkout.billing_same', true)) {
                $session->remove('checkout.billing_address_id');
            }
            $this->addFlash('success', 'Adresse ajoutée et sélectionnée.');
            return $this->redirectToRoute('checkout');
        }

        // id éventuellement déjà choisi (pour la pré-sélection radio)
        $selectedId = $session->get('checkout.address_id');

        return $this->render('checkout/address.html.twig', [
            'addresses'   => $addresses,
            'selectedId'  => $selectedId,
            'addressForm' => $form->createView(),
        ]);
    }
}
