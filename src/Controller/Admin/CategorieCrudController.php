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
            ->setEntityLabelInPlural('Catégories');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            TextField::new('nom', 'Nom'),
            TextareaField::new('description', 'Description')->hideOnIndex(),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $hideDeleteWhenNotEmpty = fn (Action $a) => $a->displayIf(
            fn (Categorie $c) => $c->getArticles()->count() === 0
        );

        return $actions
            ->update(Crud::PAGE_INDEX, Action::DELETE, $hideDeleteWhenNotEmpty)
            ->update(Crud::PAGE_DETAIL, Action::DELETE, $hideDeleteWhenNotEmpty);
    }
}
