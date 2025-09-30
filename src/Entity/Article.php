<?php

namespace App\Entity;

use App\Repository\ArticleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[Vich\Uploadable]
class Article
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $prix = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Nom du fichier de l’image principale
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    /**
     * Fichier uploadé pour l’image principale
     * - Limité à 8 Mo
     * - Formats acceptés : JPEG/PNG/WebP/GIF + HEIC/HEIF (iPhone)
     */
    #[Vich\UploadableField(mapping: 'article_image', fileNameProperty: 'image')]
    #[Assert\File(
        maxSize: '8M',
        mimeTypes: [
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'image/heic', 'image/heif'
        ],
        mimeTypesMessage: 'Format d’image non pris en charge.'
    )]
    private ?File $imageFile = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $weightKg = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $lengthCm = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $widthCm = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    private ?string $heightCm = null;

    #[ORM\ManyToOne(inversedBy: 'articles')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Categorie $categorie = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $slug = null;

    /**
     * Images secondaires (galerie, max 10)
     * @var Collection<int, ArticleImage>
     */
    #[ORM\OneToMany(mappedBy: 'article', targetEntity: ArticleImage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Count(max: 10, maxMessage: 'Vous pouvez ajouter jusqu’à {{ limit }} images.')]
    private Collection $images;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->images = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->createdAt === null) {
            $this->createdAt = new \DateTimeImmutable();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return (string) $this->getTitre();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getPrix(): ?string
    {
        return $this->prix;
    }

    public function setPrix(string $prix): static
    {
        $this->prix = $prix;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;
        return $this;
    }

    public function setImageFile(?File $imageFile): self
    {
        $this->imageFile = $imageFile;
        if ($imageFile !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getImageFile(): ?File
    {
        return $this->imageFile;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getWeightKg(): ?string
    {
        return $this->weightKg;
    }

    public function setWeightKg(?string $weightKg): static
    {
        $this->weightKg = $weightKg;
        return $this;
    }

    public function getLengthCm(): ?string
    {
        return $this->lengthCm;
    }

    public function setLengthCm(?string $lengthCm): static
    {
        $this->lengthCm = $lengthCm;
        return $this;
    }

    public function getWidthCm(): ?string
    {
        return $this->widthCm;
    }

    public function setWidthCm(?string $widthCm): static
    {
        $this->widthCm = $widthCm;
        return $this;
    }

    public function getHeightCm(): ?string
    {
        return $this->heightCm;
    }

    public function setHeightCm(?string $heightCm): static
    {
        $this->heightCm = $heightCm;
        return $this;
    }

    public function getCategorie(): ?Categorie
    {
        return $this->categorie;
    }

    public function setCategorie(?Categorie $categorie): static
    {
        $this->categorie = $categorie;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    /** Helpers métier */
    public function isDisponible(): bool
    {
        return ($this->quantity ?? 0) > 0;
    }

    public function isVendu(): bool
    {
        return ($this->quantity ?? 0) === 0;
    }

    /** ---- Gestion de la galerie ---- */

    /** @return Collection<int, ArticleImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(ArticleImage $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setArticle($this);
        }
        return $this;
    }

    public function removeImage(ArticleImage $image): self
    {
        if ($this->images->removeElement($image)) {
            if ($image->getArticle() === $this) {
                $image->setArticle(null);
            }
        }
        return $this;
    }
}
