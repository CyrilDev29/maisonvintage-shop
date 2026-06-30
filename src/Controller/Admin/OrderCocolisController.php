<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Repository\AddressRepository;
use App\Repository\ArticleRepository;
use App\Repository\OrderRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ecran admin dedie : creation manuelle d'une commande expediee via Cocolis.
 * Utilise pour les articles volumineux (ou tout autre envoi necessitant Cocolis)
 * payes par virement bancaire, en dehors du tunnel de paiement Stripe classique.
 *
 * Totalement independant d'OrderCrudController pour ne pas risquer de casser
 * le CRUD existant : c'est un formulaire metier dedie, pas un CRUD generique.
 */
#[IsGranted('ROLE_ADMIN')]
class OrderCocolisController extends AbstractController
{
    #[Route('/admin/order/cocolis/new', name: 'admin_order_cocolis_new', methods: ['GET'])]
    public function new(UserRepository $userRepository, ArticleRepository $articleRepository, AddressRepository $addressRepository): Response
    {
        // Tous les clients, tries par nom pour faciliter la recherche visuelle dans le select
        $users = $userRepository->createQueryBuilder('u')
            ->orderBy('u.nom', 'ASC')
            ->addOrderBy('u.prenom', 'ASC')
            ->getQuery()
            ->getResult();

        // Tous les articles encore en stock (pas de filtre isVolumineux : Cocolis sert aussi pour des envois standards)
        $articles = $articleRepository->createQueryBuilder('a')
            ->andWhere('a.quantity > 0')
            ->orderBy('a.titre', 'ASC')
            ->getQuery()
            ->getResult();

        // Toutes les adresses, regroupees par user.id cote JS (data attributes) pour eviter un appel AJAX
        $addresses = $addressRepository->findAll();

        return $this->render('admin/order/cocolis_new.html.twig', [
            'users'     => $users,
            'articles'  => $articles,
            'addresses' => $addresses,
        ]);
    }

    #[Route('/admin/order/cocolis/new', name: 'admin_order_cocolis_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $userRepository,
        ArticleRepository $articleRepository,
        AddressRepository $addressRepository,
        OrderRepository $orderRepository,
        \EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator $adminUrlGenerator,
        MailerInterface $mailer
    ): Response {
        // CSRF
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_order_cocolis_create', $token)) {
            $this->addFlash('danger', 'Jeton de sécurité invalide. Merci de réessayer.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }

        // --- Lecture et validation des champs postes ---
        $userId           = (int) $request->request->get('user_id');
        $addressId        = (int) $request->request->get('address_id');
        $articleId        = (int) $request->request->get('article_id');
        $qty              = (int) $request->request->get('quantity');
        $shippingPriceRaw = (string) $request->request->get('shipping_price');

        if ($userId <= 0) {
            $this->addFlash('danger', 'Merci de sélectionner un client.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }
        if ($articleId <= 0) {
            $this->addFlash('danger', 'Merci de sélectionner un article.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }
        if ($qty <= 0) {
            $this->addFlash('danger', 'La quantité doit être supérieure à 0.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }
        if ($addressId <= 0) {
            $this->addFlash('danger', 'Merci de sélectionner une adresse de livraison.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }

        // Le prix de transport doit etre un nombre valide (virgule ou point accepte), >= 0
        $shippingPriceNormalized = str_replace(',', '.', trim($shippingPriceRaw));
        if ($shippingPriceNormalized === '' || !is_numeric($shippingPriceNormalized) || (float) $shippingPriceNormalized < 0) {
            $this->addFlash('danger', 'Le prix de transport est invalide.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }
        $shippingPrice = (float) $shippingPriceNormalized;

        // --- Chargement et verification des entites ---
        $user = $userRepository->find($userId);
        if (!$user instanceof User) {
            $this->addFlash('danger', 'Client introuvable.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }

        $article = $articleRepository->find($articleId);
        if (!$article instanceof Article) {
            $this->addFlash('danger', 'Article introuvable.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }

        // Securite : l'adresse selectionnee doit appartenir au client selectionne,
        // sinon on pourrait expedier chez quelqu'un d'autre par erreur de manipulation du formulaire
        $address = $addressRepository->findOneBy(['id' => $addressId, 'user' => $user]);
        if (!$address) {
            $this->addFlash('danger', 'Cette adresse n\'appartient pas au client sélectionné.');
            return $this->redirectToRoute('admin_order_cocolis_new');
        }

        // Verification du stock au moment de la creation (peut avoir change depuis le chargement du formulaire)
        $currentStock = (int) $article->getQuantity();
        if ($qty > $currentStock) {
            $this->addFlash('danger', sprintf(
                'Stock insuffisant pour "%s" : %d disponible(s), %d demandé(s).',
                $article->getTitre(),
                $currentStock,
                $qty
            ));
            return $this->redirectToRoute('admin_order_cocolis_new');
        }

        $price    = (float) $article->getPrix();
        $subtotal = $price * $qty;

        $conn = $em->getConnection();
        $conn->beginTransaction();

        try {
            $order = new Order();
            $order->setUser($user);
            $order->setStatus(OrderStatus::EN_ATTENTE_PAIEMENT);
            $order->setPaymentMethod('bank_transfer');

            // Snapshot du profil client (champs historiques de compatibilite)
            $order->setSnapshotFromUser($user);

            // Snapshot d'adresse au meme format que CheckoutController, pour que la facture s'affiche correctement
            $shippingSnapshot = [
                'fullName'   => $address->getFullName(),
                'line1'      => $address->getLine1(),
                'line2'      => $address->getLine2(),
                'postalCode' => $address->getPostalCode(),
                'city'       => $address->getCity(),
                'country'    => $address->getCountry(),
                'phone'      => $address->getPhone(),
            ];
            $order->setShippingSnapshot($shippingSnapshot);
            // Pas d'adresse de facturation distincte dans ce flux : on reprend la meme
            $order->setBillingSnapshot($shippingSnapshot);

            // Expedition Cocolis
            $order->setShippingCarrier('COCOLIS');
            $order->setShippingMethod('DOMICILE');
            $order->setShippingAmountCents((int) round($shippingPrice * 100));

            // Reservation de 72h, alignee sur le delai virement existant (cf CheckoutController)
            $order->setReservedUntil((new \DateTimeImmutable())->modify('+72 hours'));

            // Ligne de commande
            $item = new OrderItem();
            $item->setProductId($article->getId());
            $item->setProductName($article->getTitre());
            $item->setUnitPrice(number_format($price, 2, '.', ''));
            $item->setQuantity($qty);

            $img = null;
            if ($article->getImage()) {
                $img = '/uploads/articles/' . ltrim($article->getImage(), '/');
            }
            $item->setProductImage($img);

            $order->addItem($item);
            $order->setTotal(number_format($subtotal, 2, '.', ''));

            // Reference unique garantie : on boucle tant qu'elle existe deja en base (limite de securite a 10 essais)
            $reference = $this->generateUniqueReference($orderRepository);
            $order->setReference($reference);

            $em->persist($order);

            // Decrementation du stock : meme logique que pour une commande virement classique,
            // pour que l'article bascule sur "Victime de son succes" une fois epuise.
            $article->setQuantity(max(0, $currentStock - $qty));
            $em->persist($article);

            $em->flush();
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->addFlash('danger', 'Erreur lors de la création de la commande : ' . $e->getMessage());
            return $this->redirectToRoute('admin_order_cocolis_new');
        }

        // --- Envoi des emails (non bloquant : la commande reste valide meme si l'envoi echoue) ---
        $from       = (string) ($this->getParameter('app.contact_from') ?? 'no-reply@maisonvintage.test');
        $sellerTo   = (string) ($this->getParameter('app.seller_email') ?? $from);
        $bankHolder = (string) ($this->getParameter('bank.holder')   ?? '');
        $bankName   = (string) ($this->getParameter('bank.bankname') ?? '');
        $bankIban   = (string) ($this->getParameter('bank.iban')     ?? '');
        $bankBic    = (string) ($this->getParameter('bank.bic')      ?? '');

        // Email client : confirmation de commande + RIB (pas de facture, le virement n'est pas encore recu)
        $clientTo = $user->getEmail();
        if ($clientTo) {
            try {
                $emailClient = (new TemplatedEmail())
                    ->from(new Address($from, 'Maison Vintage'))
                    ->to($clientTo)
                    ->subject('Confirmation de votre commande ' . $order->getReference() . ' — en attente de virement')
                    ->htmlTemplate('emails/order_confirmation.html.twig')
                    ->context([
                        'order'         => $order,
                        'user'          => $user,
                        'bank_holder'   => $bankHolder,
                        'bank_bankname' => $bankName,
                        'bank_iban'     => $bankIban,
                        'bank_bic'      => $bankBic,
                    ]);
                $mailer->send($emailClient);
            } catch (\Throwable $mailErr) {
                $this->addFlash('warning', 'Commande créée mais l\'email client n\'a pas pu être envoyé : ' . $mailErr->getMessage());
            }
        }

        // Copie pour Francoise (meme contenu, pour archivage / suivi)
        if ($sellerTo) {
            try {
                $emailSeller = (new TemplatedEmail())
                    ->from(new Address($from, 'Maison Vintage'))
                    ->to($sellerTo)
                    ->subject('Copie — Commande Cocolis ' . $order->getReference())
                    ->htmlTemplate('emails/order_confirmation.html.twig')
                    ->context([
                        'order'         => $order,
                        'user'          => $user,
                        'bank_holder'   => $bankHolder,
                        'bank_bankname' => $bankName,
                        'bank_iban'     => $bankIban,
                        'bank_bic'      => $bankBic,
                    ]);
                $mailer->send($emailSeller);
            } catch (\Throwable $mailErr) {
                // Non bloquant
            }
        }

        $this->addFlash('success', sprintf(
            'Commande %s créée avec succès. Le client a reçu un email avec le RIB pour le virement.',
            $order->getReference()
        ));

        // Redirection vers la liste des commandes EasyAdmin.
        return $this->redirect($adminUrlGenerator
            ->setController(\App\Controller\Admin\OrderCrudController::class)
            ->setAction('index')
            ->generateUrl());
    }

    /**
     * Genere une reference de commande unique (format MV-{annee}-{6 chiffres}).
     * Boucle tant que la reference existe deja en base, avec une limite de securite
     * pour eviter toute boucle infinie en cas de probleme grave.
     */
    private function generateUniqueReference(OrderRepository $orderRepository): string
    {
        $attempts = 0;
        do {
            $reference = 'MV-' . date('Y') . '-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
            $exists = $orderRepository->findOneBy(['reference' => $reference]) !== null;
            $attempts++;
        } while ($exists && $attempts < 10);

        if ($exists) {
            // Cas extremement improbable : on force l'unicite avec un suffixe temporel
            $reference .= '-' . substr((string) microtime(true), -4);
        }

        return $reference;
    }
}
