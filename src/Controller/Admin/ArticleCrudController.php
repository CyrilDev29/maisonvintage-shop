<?php

namespace App\Controller\Admin;

use App\Entity\Article;
use App\Form\ArticleImageType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use Vich\UploaderBundle\Form\Type\VichImageType;

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
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPageTitle(Crud::PAGE_INDEX, 'Liste des articles')
            ->setPageTitle(Crud::PAGE_NEW, 'Ajouter un article')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier un article')
            ->setPageTitle(Crud::PAGE_DETAIL, 'Détail de l\'article');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::NEW, fn(Action $a) => $a->setLabel('Ajouter'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn(Action $a) => $a->setLabel('Modifier'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn(Action $a) => $a->setLabel('Supprimer'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->onlyOnIndex();

        yield Field::new('id', 'Aperçu')
            ->setTemplatePath('admin/fields/article_thumbnail.html.twig')
            ->onlyOnIndex();

        yield FormField::addPanel('Informations générales');

        yield TextField::new('titre', 'Titre');
        yield TextField::new('slug', 'Slug')->onlyOnIndex();
        yield TextareaField::new('description', 'Description')->hideOnIndex();

        yield MoneyField::new('prix', 'Prix')
            ->setCurrency('EUR')
            ->setNumDecimals(2)
            ->setStoredAsCents(false);

        yield AssociationField::new('categorie', 'Catégorie');

        yield IntegerField::new('quantity', 'Quantité')
            ->setFormTypeOption('attr', ['min' => 0]);

        yield FormField::addPanel('📦 Expédition — À REMPLIR OBLIGATOIREMENT')
            ->setHelp('⚠️ Ces informations sont indispensables pour calculer les frais de port. Sans le poids, un tarif par défaut incorrect sera appliqué. Pour un petit objet (bougie, vase) : quelques centaines de grammes. Pour un meuble : plusieurs kilogrammes.');

        yield NumberField::new('weightKg', 'Poids (kg) *')
            ->setNumDecimals(2)
            ->setFormTypeOption('attr', ['step' => '0.01', 'placeholder' => 'Ex: 0.5 pour 500g, 15 pour un meuble'])
            ->setHelp('Poids en kilogrammes. Exemples : bougie = 0.3, vase = 0.8, fauteuil = 12, buffet = 35')
            ->hideOnIndex();

        yield NumberField::new('lengthCm', 'Longueur (cm)')
            ->setNumDecimals(2)
            ->setFormTypeOption('attr', ['step' => '0.1', 'placeholder' => 'Ex: 80'])
            ->setHelp('Longueur en centimètres')
            ->hideOnIndex();

        yield NumberField::new('widthCm', 'Largeur (cm)')
            ->setNumDecimals(2)
            ->setFormTypeOption('attr', ['step' => '0.1', 'placeholder' => 'Ex: 40'])
            ->setHelp('Largeur en centimètres')
            ->hideOnIndex();

        yield NumberField::new('heightCm', 'Hauteur (cm)')
            ->setNumDecimals(2)
            ->setFormTypeOption('attr', ['step' => '0.1', 'placeholder' => 'Ex: 60'])
            ->setHelp('Hauteur en centimètres')
            ->hideOnIndex();

        yield FormField::addPanel('Images');

        yield TextField::new('imageFile', 'Image principale')
            ->setFormType(VichImageType::class)
            ->onlyOnForms();

        yield CollectionField::new('images', 'Galerie (max 10)')
            ->setEntryType(ArticleImageType::class)
            ->setFormTypeOption('by_reference', false)
            ->onlyOnForms();

        yield FormField::addPanel('Métadonnées');

        yield DateTimeField::new('createdAt', 'Créé le')->onlyOnIndex();
        yield DateTimeField::new('updatedAt', 'Mis à jour le')->onlyOnIndex();
    }
}
