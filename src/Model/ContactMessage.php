<?php

namespace App\Model;

use Symfony\Component\Validator\Constraints as Assert;

class ContactMessage
{
    #[Assert\NotBlank(message: "Veuillez entrer votre nom.")]
    private ?string $name = null;

    #[Assert\NotBlank(message: "Veuillez entrer une adresse email.")]
    #[Assert\Email(message: "L'adresse email n'est pas valide.")]
    private ?string $email = null;

    #[Assert\NotBlank(message: "Veuillez entrer un sujet.")]
    private ?string $subject = null;

    #[Assert\NotBlank(message: "Veuillez entrer un message.")]
    #[Assert\Length(min: 10, minMessage: "Votre message doit contenir au moins {{ limit }} caractères.")]
    private ?string $message = null;

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): self { $this->name = $name; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getSubject(): ?string { return $this->subject; }
    public function setSubject(?string $subject): self { $this->subject = $subject; return $this; }

    public function getMessage(): ?string { return $this->message; }
    public function setMessage(?string $message): self { $this->message = $message; return $this; }
}
