<?php

namespace App\Form\Account;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', null, ['label' => 'Prénom', 'row_attr' => ['class' => 'mb-3']])
            ->add('nom', null, ['label' => 'Nom', 'row_attr' => ['class' => 'mb-3']])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'row_attr' => ['class' => 'mb-3'],
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('telephone', TelType::class, [
                'label' => 'Téléphone',
                'row_attr' => ['class' => 'mb-3'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('rue', null, ['label' => 'Rue', 'row_attr' => ['class' => 'mb-3']])
            ->add('codePostal', null, ['label' => 'Code postal', 'row_attr' => ['class' => 'mb-3']])
            ->add('ville', null, ['label' => 'Ville', 'row_attr' => ['class' => 'mb-3']])
            ->add('pays', CountryType::class, [
                'label' => 'Pays',
                'preferred_choices' => ['FR'],
                'row_attr' => ['class' => 'mb-3'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
