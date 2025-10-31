<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Service\InvoiceService;
use App\Service\StripePaymentService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class OrderCrudController extends AbstractCrudController
{
    public function __construct(
        private MailerInterface $mailer,
        private ParameterBagInterface $params,
        private StripePaymentService $stripe,
        private CsrfTokenManagerInterface $csrf,
        private InvoiceService $invoiceService, // <-- ajouté (génération facture)
    ) {}

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Commande')
            ->setEntityLabelInPlural('Commandes')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPageTitle(Crud::PAGE_INDEX, 'Liste des commandes')
            ->setPageTitle(Crud::PAGE_NEW, 'Nouvelle commande')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier une commande')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détails de la commande');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('reference', 'Référence')->onlyOnIndex();
        yield AssociationField::new('user', 'Client')->hideOnForm();

        yield TextField::new('status', 'Statut')
            ->setTemplatePath('admin/fields/order_status_badge.html.twig')
            ->hideOnForm();

        yield ChoiceField::new('status', 'Statut')
            ->setChoices([
                'En attente de paiement' => OrderStatus::EN_ATTENTE_PAIEMENT,
                'En cours'               => OrderStatus::EN_COURS,
                'En préparation'         => OrderStatus::EN_PREPARATION,
                'Expédiée'               => OrderStatus::EXPEDIEE,
                'Annulée'                => OrderStatus::ANNULEE,
                'Livrée'                 => OrderStatus::LIVREE,
            ])
            ->onlyOnForms();

        yield MoneyField::new('total', 'Total')
            ->setCurrency('EUR')
            ->setStoredAsCents(false);

        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Mise à jour le')->hideOnForm();

        yield FormField::addPanel('Coordonnées du client')->onlyOnDetail();
        yield TextField::new('prenom', 'Prénom')->onlyOnDetail();
        yield TextField::new('nom', 'Nom')->onlyOnDetail();
        yield TextField::new('telephone', 'Téléphone')->onlyOnDetail();
        yield TextField::new('user.email', 'E-mail')->onlyOnDetail();
        yield TextField::new('rue', 'Rue')->onlyOnDetail();
        yield TextField::new('codePostal', 'Code postal')->onlyOnDetail();
        yield TextField::new('ville', 'Ville')->onlyOnDetail();
        yield TextField::new('pays', 'Pays')->onlyOnDetail();

        yield FormField::addPanel('Articles')->onlyOnDetail();
        yield CollectionField::new('items', '')->onlyOnDetail()
            ->setTemplatePath('admin/order/_items.html.twig');

        yield FormField::addPanel('Paiement / Stripe')->onlyOnDetail();
        yield TextField::new('stripePaymentIntentId', 'PaymentIntent')->onlyOnDetail();
        yield TextField::new('stripeSessionId', 'Checkout Session')->onlyOnDetail();
        yield TextField::new('stripeRefundId', 'Refund ID')->onlyOnDetail();
        yield DateTimeField::new('canceledAt', 'Annulée le')->onlyOnDetail();
        yield DateTimeField::new('refundedAt', 'Remboursée le')->onlyOnDetail();
    }

    public function configureActions(Actions $actions): Actions
    {
        $refund = Action::new('stripeRefund', 'Rembourser Stripe')
            ->linkToRoute('admin_order_refund', function (Order $order) {
                return [
                    'id'     => $order->getId(),
                    '_token' => $this->csrf->getToken('admin_refund_' . $order->getId())->getValue(),
                ];
            })
            ->setCssClass('btn btn-danger')
            ->setHtmlAttributes([
                'data-ea-method'    => 'post',
                'data-action-name'  => 'post',
                'data-confirm-text' => 'Confirmer le remboursement Stripe ?',
            ])
            ->displayIf(function (Order $order): bool {
                if ($order->getStatus() !== OrderStatus::ANNULEE) return false;
                if (!$order->getStripePaymentIntentId()) return false;
                if ($order->getStripeRefundId()) return false;
                return true;
            });

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $refund)
            ->update(Crud::PAGE_INDEX, Action::NEW, fn(Action $a) => $a->setLabel('Ajouter commande'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn(Action $a) => $a->setLabel('Modifier'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn(Action $a) => $a->setLabel('Supprimer'))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn(Action $a) => $a->setLabel('Détails'));
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if (!$entityInstance instanceof Order) {
            parent::updateEntity($em, $entityInstance);
            return;
        }

        $uow = $em->getUnitOfWork();
        $original  = $uow->getOriginalEntityData($entityInstance);
        $oldStatus = $original['status'] ?? null;
        $newStatus = $entityInstance->getStatus();

        parent::updateEntity($em, $entityInstance);

        // Envoi d’email au client à chaque changement de statut
        if ($oldStatus !== $newStatus && $entityInstance->getUser()?->getEmail()) {
            $from     = (string) ($this->params->get('app.contact_from') ?? 'no-reply@maisonvintage.test');
            $sellerTo = (string) ($this->params->get('app.seller_email') ?? $from);

            // Contexte commun (inclut RIB pour l'état "En attente ...")
            $context = [
                'order'         => $entityInstance,
                'bank_holder'   => (string) ($this->params->get('bank.holder')   ?? ''),
                'bank_bankname' => (string) ($this->params->get('bank.bankname') ?? ''),
                'bank_iban'     => (string) ($this->params->get('bank.iban')     ?? ''),
                'bank_bic'      => (string) ($this->params->get('bank.bic')      ?? ''),
            ];

            // Mail client (status update)
            try {
                $emailClient = (new TemplatedEmail())
                    ->from(new Address($from, 'Maison Vintage'))
                    ->to($entityInstance->getUser()->getEmail())
                    ->subject('Mise à jour de votre commande ' . $entityInstance->getReference())
                    ->htmlTemplate('emails/order_status_update.html.twig')
                    ->context($context);

                // Si la commande devient EN_COURS : générer et joindre la facture
                if ($newStatus === OrderStatus::EN_COURS) {
                    $pdfPath = $this->invoiceService->generate($entityInstance, false); // idempotent
                    if (is_string($pdfPath) && is_file($pdfPath)) {
                        $emailClient->attachFromPath($pdfPath, sprintf('Facture-%s.pdf', $entityInstance->getReference()));
                    }
                }

                $this->mailer->send($emailClient);
            } catch (\Throwable $e) {
                // Non bloquant pour l’admin
            }

            // Copie à la vendeuse avec la facture si EN_COURS
            if ($newStatus === OrderStatus::EN_COURS && $sellerTo) {
                try {
                    $emailSeller = (new TemplatedEmail())
                        ->from(new Address($from, 'Maison Vintage'))
                        ->to($sellerTo)
                        ->subject('Copie facture — ' . $entityInstance->getReference())
                        ->htmlTemplate('emails/order_status_update.html.twig')
                        ->context($context);

                    $clientPdf = $this->invoiceService->generate($entityInstance, false);
                    if (is_string($clientPdf) && is_file($clientPdf)) {
                        $emailSeller->attachFromPath($clientPdf, sprintf('Facture-%s.pdf', $entityInstance->getReference()));
                    }

                    $this->mailer->send($emailSeller);
                } catch (\Throwable $e) {
                    // Non bloquant
                }
            }
        }
    }

    #[Route('/admin/order/{id}/refund', name: 'admin_order_refund', methods: ['POST'])]
    public function stripeRefund(Request $request, AdminContext $context, EntityManagerInterface $em): RedirectResponse
    {
        $entity = $context->getEntity()->getInstance();
        $referrer = $context->getReferrer() ?? $this->generateUrl('admin');

        if (!$entity instanceof Order) {
            $this->addFlash('danger', 'Commande introuvable.');
            return $this->redirect($referrer);
        }

        if (!$this->isCsrfTokenValid(
            'admin_refund_' . $entity->getId(),
            (string) ($request->request->get('_token') ?? $request->query->get('_token'))
        )) {
            $this->addFlash('danger', 'Jeton CSRF invalide.');
            return $this->redirect($referrer);
        }

        if ($entity->getStatus() !== OrderStatus::ANNULEE) {
            $this->addFlash('warning', 'La commande doit être annulée avant remboursement.');
            return $this->redirect($referrer);
        }
        if (!$entity->getStripePaymentIntentId()) {
            $this->addFlash('warning', 'Aucun paiement Stripe associé à cette commande.');
            return $this->redirect($referrer);
        }
        if ($entity->getStripeRefundId()) {
            $this->addFlash('info', 'Cette commande est déjà remboursée.');
            return $this->redirect($referrer);
        }

        try {
            $refundId = $this->stripe->refundPaymentIntent(
                $entity->getStripePaymentIntentId(),
                null,
                'requested_by_customer'
            );

            $entity->setStripeRefundId($refundId);
            $entity->setRefundedAt(new DateTimeImmutable());
            $em->flush();

            $this->addFlash('success', 'Remboursement Stripe effectué avec succès.');
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Échec du remboursement Stripe : ' . $e->getMessage());
        }

        return $this->redirect($referrer);
    }
}
