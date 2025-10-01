<?php

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
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
use Symfony\Component\Mailer\MailerInterface;

class OrderCrudController extends AbstractCrudController
{
    public function __construct(
        private MailerInterface $mailer,
        private ParameterBagInterface $params,
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
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier une commande')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détails de la commande');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();
        yield TextField::new('reference', 'Référence')->onlyOnIndex();
        yield AssociationField::new('user', 'Client')->hideOnForm();

        yield ChoiceField::new('status', 'Statut')
            ->setChoices([
                'En cours'        => OrderStatus::EN_COURS,
                'En préparation'  => OrderStatus::EN_PREPARATION,
                'Expédiée'        => OrderStatus::EXPEDIEE,
                'Annulée'         => OrderStatus::ANNULEE,
                'Livrée'          => OrderStatus::LIVREE,
            ])
            ->renderAsBadges([
                OrderStatus::EN_COURS->value        => 'warning',
                OrderStatus::EN_PREPARATION->value  => 'info',
                OrderStatus::EXPEDIEE->value        => 'primary',
                OrderStatus::ANNULEE->value         => 'danger',
                OrderStatus::LIVREE->value          => 'success',
            ]);

        yield MoneyField::new('total', 'Total')->setCurrency('EUR')->setStoredAsCents(false);
        yield DateTimeField::new('createdAt', 'Créée le')->hideOnForm();
        yield DateTimeField::new('updatedAt', 'Mise à jour')->hideOnForm();

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
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn(Action $a) => $a->setLabel('Modifier'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn(Action $a) => $a->setLabel('Supprimer'))
            ->update(Crud::PAGE_INDEX, Action::DETAIL, fn(Action $a) => $a->setLabel('Voir détails'));
    }

    public function updateEntity(EntityManagerInterface $em, $entityInstance): void
    {
        if (!$entityInstance instanceof Order) {
            parent::updateEntity($em, $entityInstance);
            return;
        }

        $uow = $em->getUnitOfWork();
        $original = $uow->getOriginalEntityData($entityInstance);
        $old = $original['status'] ?? null;
        $new = $entityInstance->getStatus();

        parent::updateEntity($em, $entityInstance);

        if ($old !== $new && $entityInstance->getUser()?->getEmail()) {
            $from = $this->params->has('app.contact_from')
                ? (string) $this->params->get('app.contact_from')
                : 'no-reply@maisonvintage.test';

            $email = (new TemplatedEmail())
                ->from($from)
                ->to($entityInstance->getUser()->getEmail())
                ->subject('Mise à jour de votre commande ' . $entityInstance->getReference())
                ->htmlTemplate('emails/order_status_update.html.twig')
                ->context([
                    'order' => $entityInstance,
                ]);

            $this->mailer->send($email);
        }
    }
}
