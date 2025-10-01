<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Cet e-mail est déjà utilisé.')]
#[UniqueEntity(fields: ['telephone'], message: 'Ce numéro de téléphone est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank(message: 'L’email est obligatoire.')]
    #[Assert\Email(message: 'Email invalide.')]
    private ?string $email = null;

    /**
     * @var list<string>
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string|null The hashed password
     */
    #[ORM\Column(nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    private ?string $prenom = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    private ?string $nom = null;

    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank(message: 'Le téléphone est obligatoire.')]
    #[Assert\Length(min: 6, max: 20, minMessage: 'Téléphone trop court.')]
    private ?string $telephone = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'La rue est obligatoire.')]
    private ?string $rue = null;

    #[ORM\Column(length: 10)]
    #[Assert\NotBlank(message: 'Le code postal est obligatoire.')]
    private ?string $codePostal = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'La ville est obligatoire.')]
    private ?string $ville = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Le pays est obligatoire.')]
    private ?string $pays = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Order::class, orphanRemoval: true)]
    private Collection $orders;

    public function __construct()
    {
        $this->orders = new ArrayCollection();
    }

    // --- getters / setters existants ---

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = $email; return $this; }

    public function getUserIdentifier(): string { return (string) $this->email; }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';
        return array_unique($roles);
    }

    public function setRoles(array $roles): static { $this->roles = $roles; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0password"] = $this->password ? hash('crc32c', $this->password) : null;
        return $data;
    }

    #[\Deprecated]
    public function eraseCredentials(): void {}

    public function getPrenom(): ?string { return $this->prenom; }
    public function setPrenom(string $prenom): static { $this->prenom = $prenom; return $this; }

    public function getNom(): ?string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

    public function getTelephone(): ?string { return $this->telephone; }
    public function setTelephone(string $telephone): static
    {
        $normalized = preg_replace('/(?!^\+)\D+/', '', trim($telephone));
        $this->telephone = $normalized ?? $telephone;
        return $this;
    }

    public function getRue(): ?string { return $this->rue; }
    public function setRue(string $rue): static { $this->rue = $rue; return $this; }

    public function getCodePostal(): ?string { return $this->codePostal; }
    public function setCodePostal(string $codePostal): static { $this->codePostal = $codePostal; return $this; }

    public function getVille(): ?string { return $this->ville; }
    public function setVille(string $ville): static { $this->ville = $ville; return $this; }

    public function getPays(): ?string { return $this->pays; }
    public function setPays(string $pays): static { $this->pays = $pays; return $this; }

    public function isVerified(): bool { return $this->isVerified; }
    public function setIsVerified(bool $isVerified): static { $this->isVerified = $isVerified; return $this; }

    // --- Relation avec Order ---

    /**
     * @return Collection<int, Order>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): static
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setUser($this);
        }
        return $this;
    }

    public function removeOrder(Order $order): static
    {
        if ($this->orders->removeElement($order)) {
            if ($order->getUser() === $this) {
                $order->setUser(null);
            }
        }
        return $this;
    }
}
