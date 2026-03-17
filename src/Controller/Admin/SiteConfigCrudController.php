<?php

namespace App\Controller\Admin;

use App\Entity\SiteConfig;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

class SiteConfigCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return SiteConfig::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Configuration')
            ->setEntityLabelInPlural('Configuration du site')
            ->setPageTitle(Crud::PAGE_INDEX, 'Configuration du site')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier la configuration');
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            ImageField::new('heroImage', 'Photo hero (page d\'accueil)')
                ->setBasePath('uploads/hero')
                ->setUploadDir('public/uploads/hero')
                ->setUploadedFileNamePattern('[name].[extension]')
                ->setRequired(false),
        ];
    }
}
