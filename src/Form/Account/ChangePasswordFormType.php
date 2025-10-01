<?php

namespace App\Form\Account;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Possibilité d'ajouter un oldPassword (non mappé) si tu veux vérifier l’ancien
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'row_attr' => ['class' => 'mb-3'],
                    'attr' => ['autocomplete' => 'new-password'],
                    'help' => 'Min. 8 caractères, au moins 1 majuscule et 1 caractère spécial.',
                ],
                'second_options' => [
                    'label' => 'Confirmez le mot de passe',
                    'row_attr' => ['class' => 'mb-3'],
                    'attr' => ['autocomplete' => 'new-password'],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le mot de passe est obligatoire.'),
                    new Assert\Length(min: 8, minMessage: 'Au moins 8 caractères.'),
                    new Assert\Regex(
                        pattern: '/^(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,}$/',
                        message: 'Le mot de passe doit contenir au moins 1 majuscule et 1 caractère spécial.'
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
