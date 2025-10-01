<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Identité
            ->add('prenom', null, [
                'label' => 'Prénom',
                'required' => true,
                'row_attr' => ['class' => 'mb-3'],
                'attr' => ['placeholder' => 'Ex. Françoise'],
                'constraints' => [new Assert\NotBlank(message: 'Le prénom est obligatoire.')],
            ])
            ->add('nom', null, [
                'label' => 'Nom',
                'required' => true,
                'row_attr' => ['class' => 'mb-3'],
                'attr' => ['placeholder' => 'Ex. Martin'],
                'constraints' => [new Assert\NotBlank(message: 'Le nom est obligatoire.')],
            ])

            // Contact
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => true,
                'row_attr' => ['class' => 'mb-3'],
                'attr' => ['placeholder' => 'vous@example.com', 'autocomplete' => 'email'],
                'constraints' => [
                    new Assert\NotBlank(message: 'L’email est obligatoire.'),
                    new Assert\Email(message: 'Email invalide.'),
                ],
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'required' => true,
                'row_attr' => ['class' => 'mb-3'],
                'attr' => ['placeholder' => 'Ex. +33612345678'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le téléphone est obligatoire.'),
                    new Assert\Length(min: 6, max: 20, minMessage: 'Téléphone trop court.'),
                ],
            ])

            // Adresse
            ->add('rue', null, [
                'label' => 'Rue',
                'required' => true,
                'row_attr' => ['class' => 'mb-3'],
                'attr' => ['placeholder' => 'Numéro et voie'],
                'constraints' => [new Assert\NotBlank(message: 'La rue est obligatoire.')],
            ])
            ->add('codePostal', null, [
                'label' => 'Code postal',
                'required' => true,
                'row_attr' => ['class' => 'mb-3'],
                'attr' => ['placeholder' => 'Ex. 29200'],
                'constraints' => [new Assert\NotBlank(message: 'Le code postal est obligatoire.')],
            ])
            ->add('ville', null, [
                'label' => 'Ville',
                'required' => true,
                'row_attr' => ['class' => 'mb-3'],
                'attr' => ['placeholder' => 'Ex. Brest'],
                'constraints' => [new Assert\NotBlank(message: 'La ville est obligatoire.')],
            ])
            ->add('pays', CountryType::class, [
                'label' => 'Pays',
                'required' => true,
                'preferred_choices' => ['FR'],
                'placeholder' => 'Sélectionnez un pays',
                'row_attr' => ['class' => 'mb-3'],
                'constraints' => [new Assert\NotBlank(message: 'Le pays est obligatoire.')],
            ])

            // Mot de passe : double saisie + règles de complexité
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => true,
                'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
                'first_options'  => [
                    'label' => 'Mot de passe',
                    'row_attr' => ['class' => 'mb-3'],
                    'attr' => [
                        'id' => 'pwd1',
                        'autocomplete' => 'new-password',
                        'placeholder' => '••••••••',
                    ],
                    'help' => 'Min. 8 caractères, au moins 1 majuscule et 1 caractère spécial.',
                ],
                'second_options' => [
                    'label' => 'Confirmez le mot de passe',
                    'row_attr' => ['class' => 'mb-3'],
                    'attr' => [
                        'id' => 'pwd2',
                        'autocomplete' => 'new-password',
                        'placeholder' => '••••••••',
                    ],
                ],
                'constraints' => [
                    new Assert\NotBlank(message: 'Le mot de passe est obligatoire.'),
                    new Assert\Length(min: 8, minMessage: 'Au moins 8 caractères.'),
                    new Assert\Regex(
                        pattern: '/^(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,}$/',
                        message: 'Le mot de passe doit contenir au moins 1 majuscule et 1 caractère spécial.'
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
