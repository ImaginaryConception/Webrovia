<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class DomainType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('domainName', TextType::class, [
                'label' => 'Nom de domaine',
                'required' => true,
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer un nom de domaine',
                    ]),
                    new Regex([
                        'pattern' => '/^[a-zA-Z0-9-]+$/',
                        'message' => 'Le nom de domaine ne peut contenir que des lettres, des chiffres et des tirets',
                    ]),
                ],
                'attr' => [
                    'placeholder' => 'Votre nom de domaine',
                    'class' => 'flex-grow px-4 py-2 border rounded-l-lg dark:bg-gray-700 dark:text-white dark:border-gray-600',
                ],
            ])
            ->add('extension', ChoiceType::class, [
                'label' => 'Extension',
                'required' => true,
                'choices' => [
                    '.com' => '.com',
                    '.fr' => '.fr',
                    '.org' => '.org',
                    '.net' => '.net',
                    '.eu' => '.eu',
                ],
                'attr' => [
                    'class' => 'px-4 py-2 border rounded-r-lg bg-white dark:bg-gray-700 dark:text-white dark:border-gray-600',
                ],
            ])
            ->add('price', TextType::class, [
                'label' => false,
                'required' => false,
                'mapped' => true,
                'attr' => [
                    'class' => 'domain-price-input hidden',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}