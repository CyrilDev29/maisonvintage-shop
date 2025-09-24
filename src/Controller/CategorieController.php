<?php

namespace App\Controller;

use App\Repository\CategorieRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CategorieController extends AbstractController
{
    #[Route('/categorie/{slug}', name: 'categorie_show')]
    public function show(string $slug, CategorieRepository $categorieRepository): Response
    {
        $categorie = $categorieRepository->findOneBy(['slug' => $slug]);

        if (!$categorie) {
            throw $this->createNotFoundException('Catégorie introuvable');
        }

        return $this->render('categorie/show.html.twig', [
            'categorie' => $categorie,
            'articles'  => $categorie->getArticles(),
        ]);
    }
}
