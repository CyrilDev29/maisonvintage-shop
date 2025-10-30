<?php

namespace App\Controller\Admin;

use App\Entity\Categorie;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;

class CategorieCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Categorie::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Catégorie')
            ->setEntityLabelInPlural('Catégories')
            ->setPageTitle(Crud::PAGE_INDEX, 'Liste des catégories')
            ->setPageTitle(Crud::PAGE_NEW, 'Ajouter une catégorie')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier une catégorie')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détail de la catégorie');
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions->add(Crud::PAGE_INDEX, Action::DETAIL);

        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, fn(Action $a) => $a->setLabel('Ajouter'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn(Action $a) => $a->setLabel('Modifier'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn(Action $a) => $a->setLabel('Supprimer'));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('nom', 'Nom'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
        ];
    }
}
