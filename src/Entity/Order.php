<?php

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: '`order`')]
#[ORM\HasLifecycleCallbacks]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Référence lisible par l’utilisateur (ex: MV-2025-000123) */
    #[ORM\Column(length: 32, unique: true)]
    #[Assert\NotBlank]
    private ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'orders')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    /** Statut stocké comme enum (string) */
    #[ORM\Column(enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::EN_COURS;

    /** Total TTC de la commande (EUR) */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    #[Assert\NotNull]
    private string $total = '0.00';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTime $updatedAt;

    /** Lignes */
    #[ORM\OneToMany(mappedBy: 'order', targetEntity: OrderItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    /**
     * --- SNAPSHOT HISTORIQUE (compat) ---
     * Ces champs reprennent l’adresse (profil) au moment de la commande.
     * Conservés pour ne rien casser si déjà utilisés dans tes PDFs / vues.
     */
    #[ORM\Column(length: 100)]
    private string $prenom;

    #[ORM\Column(length: 100)]
    private string $nom;

    #[ORM\Column(length: 20)]
    private string $telephone;

    #[ORM\Column(length: 255)]
    private string $rue;

    #[ORM\Column(length: 10)]
    private string $codePostal;

    #[ORM\Column(length: 100)]
    private string $ville;

    #[ORM\Column(length: 100)]
    private string $pays;

    /**
     * --- NOUVEAU : Snapshots d’adresses structurés (JSON) ---
     * Copie exacte des adresses choisies (livraison/facturation) lors du checkout.
     * Clés usuelles: fullName, line1, line2, postalCode, city, country, phone (optionnel)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shippingSnapshot = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $billingSnapshot = null;

    /** Facture déjà envoyée par email au client ? (anti double-envoi) */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $invoiceSent = false;

    /* ---------- Champs Stripe / annulation / remboursement (ajouts) ---------- */

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeSessionId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $canceledAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $refundedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $stripeRefundId = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTime();
        $this->status = OrderStatus::EN_COURS;
        $this->invoiceSent = false;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }

    // --- Getters / setters ---

    public function getId(): ?int { return $this->id; }

    public function getReference(): ?string { return $this->reference; }
    public function setReference(string $reference): static { $this->reference = $reference; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): static { $this->user = $user; return $this; }

    public function getStatus(): OrderStatus { return $this->status; }
    public function setStatus(OrderStatus $status): static { $this->status = $status; return $this; }

    public function getTotal(): string { return $this->total; }
    public function setTotal(string $total): static { $this->total = $total; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTime { return $this->updatedAt; }

    /** @return Collection<int, OrderItem> */
    public function getItems(): Collection { return $this->items; }

    public function addItem(OrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }
        return $this;
    }

    public function removeItem(OrderItem $item): static
    {
        if ($this->items->removeElement($item) && $item->getOrder() === $this) {
            $item->setOrder(null);
        }
        return $this;
    }

    /**
     * Snapshot “historique” depuis le profil User (compat).
     * NOTE: aujourd’hui tu utilises plutôt shippingSnapshot / billingSnapshot
     * au moment du confirm. On garde ceci pour ne rien casser.
     */
    public function setSnapshotFromUser(User $u): static
    {
        $this->prenom     = (string) $u->getPrenom();
        $this->nom        = (string) $u->getNom();
        $this->telephone  = (string) $u->getTelephone();
        $this->rue        = (string) $u->getRue();
        $this->codePostal = (string) $u->getCodePostal();
        $this->ville      = (string) $u->getVille();
        $this->pays       = (string) $u->getPays();
        return $this;
    }

    // --- Accesseurs des champs historiques ---
    public function getPrenom(): string { return $this->prenom; }
    public function getNom(): string { return $this->nom; }
    public function getTelephone(): string { return $this->telephone; }
    public function getRue(): string { return $this->rue; }
    public function getCodePostal(): string { return $this->codePostal; }
    public function getVille(): string { return $this->ville; }
    public function getPays(): string { return $this->pays; }

    // --- Snapshots JSON (nouveau modèle) ---
    public function getShippingSnapshot(): ?array { return $this->shippingSnapshot; }
    public function setShippingSnapshot(?array $snapshot): self { $this->shippingSnapshot = $snapshot; return $this; }

    public function getBillingSnapshot(): ?array { return $this->billingSnapshot; }
    public function setBillingSnapshot(?array $snapshot): self { $this->billingSnapshot = $snapshot; return $this; }

    // --- Facture envoyée ---
    public function isInvoiceSent(): bool { return $this->invoiceSent; }
    public function markInvoiceSent(): void { $this->invoiceSent = true; }

    // --- Stripe / annulation / remboursement (getters/setters) ---
    public function getStripePaymentIntentId(): ?string { return $this->stripePaymentIntentId; }
    public function setStripePaymentIntentId(?string $id): self { $this->stripePaymentIntentId = $id; return $this; }

    public function getStripeSessionId(): ?string { return $this->stripeSessionId; }
    public function setStripeSessionId(?string $id): self { $this->stripeSessionId = $id; return $this; }

    public function getCanceledAt(): ?\DateTimeImmutable { return $this->canceledAt; }
    public function setCanceledAt(?\DateTimeImmutable $dt): self { $this->canceledAt = $dt; return $this; }

    public function getRefundedAt(): ?\DateTimeImmutable { return $this->refundedAt; }
    public function setRefundedAt(?\DateTimeImmutable $dt): self { $this->refundedAt = $dt; return $this; }

    public function getStripeRefundId(): ?string { return $this->stripeRefundId; }
    public function setStripeRefundId(?string $id): self { $this->stripeRefundId = $id; return $this; }
}
