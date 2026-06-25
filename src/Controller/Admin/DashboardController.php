<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Entity\Article;
use App\Entity\Categorie;
use App\Entity\Order;
use App\Entity\SiteConfig;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

// L'attribut #[AdminDashboard] remplace l'ancien #[Route].
// Il se pose sur la classe (et plus sur la methode index), et indique
// a EasyAdmin le chemin et le nom de la route du dashboard.
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    // Methode appelee quand on visite /admin.
    // Elle rend une page d'accueil vierge pour eviter tout missclick.
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    // Configuration generale du dashboard (titre affiche en haut).
    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Maison Vintage');
    }

    // Construction du menu lateral de l'admin.
    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToCrud('Users', 'fa fa-users', User::class);
        yield MenuItem::linkToCrud('Articles', 'fa fa-box', Article::class);
        yield MenuItem::linkToCrud('Catégories', 'fa fa-tags', Categorie::class);
        yield MenuItem::linkToCrud('Commandes', 'fas fa-shopping-cart', Order::class);
        yield MenuItem::section('Paramètres');
        yield MenuItem::linkToCrud('Configuration du site', 'fa fa-cog', SiteConfig::class);
        yield MenuItem::section('');
        yield MenuItem::linkToUrl('Revenir au site', 'fa fa-house', 'https://maisonvintage.fr');
    }

    // Chargement du CSS specifique a l'admin.
    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile('/assets/styles/admin.css');
    }
}
