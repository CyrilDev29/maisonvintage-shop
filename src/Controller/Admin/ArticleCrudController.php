<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;

class ArticleCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Article::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Article')
            ->setEntityLabelInPlural('Articles')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield TextField::new('titre', 'Titre');

        yield TextareaField::new('description', 'Description')
            ->hideOnIndex();

        yield MoneyField::new('prix', 'Prix')
            ->setCurrency('EUR')
            ->setNumDecimals(2)
            ->setStoredAsCents(false);

        yield ImageField::new('image', 'Image')
            ->setBasePath('uploads/articles')
            ->setUploadDir('public/uploads/articles')
            ->setRequired(false)
            ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]');

        yield AssociationField::new('categorie', 'Catégorie');

        yield IntegerField::new('quantity', 'Quantité')
            ->setFormTypeOption('attr', ['min' => 0]);

        yield NumberField::new('weightKg', 'Poids (kg)')
            ->setNumDecimals(2)
            ->setFormTypeOption('attr', ['step' => '0.01'])
            ->hideOnIndex();

        yield NumberField::new('lengthCm', 'Longueur (cm)')
            ->setNumDecimals(2)
            ->setFormTypeOption('attr', ['step' => '0.1'])
            ->hideOnIndex();

        yield NumberField::new('widthCm', 'Largeur (cm)')
            ->setNumDecimals(2)
            ->setFormTypeOption('attr', ['step' => '0.1'])
            ->hideOnIndex();

        yield NumberField::new('heightCm', 'Hauteur (cm)')
            ->setNumDecimals(2)
            ->setFormTypeOption('attr', ['step' => '0.1'])
            ->hideOnIndex();

        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex();
        yield DateTimeField::new('updatedAt', 'Mis à jour le')->onlyOnIndex();
    }
}
