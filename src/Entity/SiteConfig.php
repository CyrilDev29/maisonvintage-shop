<?php

namespace App\Entity;

use App\Repository\SiteConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SiteConfigRepository::class)]
class SiteConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $heroImage = null;

    public function getId(): ?int { return $this->id; }

    public function getHeroImage(): ?string { return $this->heroImage; }

    public function setHeroImage(?string $heroImage): static
    {
        $this->heroImage = $heroImage;
        return $this;
    }
}
