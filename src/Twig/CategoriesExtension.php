<?php

namespace App\Twig;

use App\Service\CategoriesProvider;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class CategoriesExtension extends AbstractExtension
{
    public function __construct(private CategoriesProvider $provider) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('categories', [$this, 'getCategories']),
        ];
    }

    /** @return array<\App\Entity\Categorie> */
    public function getCategories(): array
    {
        return $this->provider->all();
    }
}
