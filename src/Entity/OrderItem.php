<?php

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ORM\Table(name: 'order_item')]
#[ORM\Index(columns: ['order_id'])]
#[ORM\Index(columns: ['product_id'])]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Order $order = null;

    /**
     * Snapshot de l'identifiant de l'article (permet de décrémenter le stock au webhook).
     * Nullable pour ne pas casser les anciennes commandes.
     */
    #[ORM\Column(name: 'product_id', type: Types::INTEGER, nullable: true)]
    private ?int $productId = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "Le nom du produit est obligatoire.")]
    private string $productName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $productImage = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    private string $unitPrice = '0.00';

    #[ORM\Column]
    #[Assert\Positive(message: "La quantité doit être positive.")]
    private int $quantity = 1;

    public function getId(): ?int { return $this->id; }

    public function getOrder(): ?Order { return $this->order; }
    public function setOrder(?Order $order): static { $this->order = $order; return $this; }

    public function getProductId(): ?int { return $this->productId; }
    public function setProductId(?int $productId): static { $this->productId = $productId; return $this; }

    public function getProductName(): string { return $this->productName; }
    public function setProductName(string $name): static { $this->productName = $name; return $this; }

    public function getProductImage(): ?string { return $this->productImage; }
    public function setProductImage(?string $image): static { $this->productImage = $image; return $this; }

    public function getUnitPrice(): string { return $this->unitPrice; }
    public function setUnitPrice(string $price): static { $this->unitPrice = $price; return $this; }

    public function getQuantity(): int { return $this->quantity; }
    public function setQuantity(int $q): static { $this->quantity = $q; return $this; }

    public function getLineTotal(): string
    {
        return number_format(((float)$this->unitPrice) * $this->quantity, 2, '.', '');
    }
}
