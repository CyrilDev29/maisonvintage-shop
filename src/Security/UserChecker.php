<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Empêche l'auth si l'email n’est pas confirmé
        if (!$user->isVerified()) {
            throw new CustomUserMessageAuthenticationException(
                'Votre e-mail n’est pas encore vérifié. Veuillez cliquer sur le lien de confirmation envoyé par e-mail.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Rien à faire ici pour l’instant
    }
}
