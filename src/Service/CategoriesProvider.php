<?php

namespace App\Service;

use App\Entity\Categorie;
use App\Repository\CategorieRepository;

class CategoriesProvider
{
    public function __construct(private CategorieRepository $repo) {}

    /** @return Categorie[] */
    public function all(): array
    {
        return $this->repo->findBy([], ['nom' => 'ASC']);
    }
}
