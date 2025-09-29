<?php

namespace App\Form;

use App\Model\ContactMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ContactType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Votre nom', 'attr' => ['maxlength' => 100]])
            ->add('email', EmailType::class, ['label' => 'Votre e-mail', 'attr' => ['maxlength' => 180]])
            ->add('subject', TextType::class, ['label' => 'Sujet', 'attr' => ['maxlength' => 150]])
            ->add('message', TextareaType::class, ['label' => 'Message', 'attr' => ['rows' => 6, 'maxlength' => 5000]])
            ->add('website', HiddenType::class, ['mapped' => false, 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ContactMessage::class]);
    }
}
