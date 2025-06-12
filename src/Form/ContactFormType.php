<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ContactFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options):
        void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Votre Nom',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer votre nom.',
                    ]),
                    new Length([
                        'min' => 2,
                        'minMessage' => 'Votre nom doit contenir au moins {{ limit }} caractères.',
                        'max' => 100,
                        'maxMessage' => 'Votre nom ne peut pas dépasser {{ limit }} caractères.',
                    ]),
                ],
                'attr' => ['class' => 'appearance-none block w-full bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded-md py-3 px-4 leading-tight focus:outline-none focus:bg-white dark:focus:bg-gray-800 focus:border-primary dark:focus:border-primary transition duration-200 ease-in-out'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Votre Email',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer votre adresse email.',
                    ]),
                    new Email([
                        'message' => 'Veuillez entrer une adresse email valide.',
                    ]),
                ],
                'attr' => ['class' => 'appearance-none block w-full bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded-md py-3 px-4 leading-tight focus:outline-none focus:bg-white dark:focus:bg-gray-800 focus:border-primary dark:focus:border-primary transition duration-200 ease-in-out'],
            ])
            ->add('subject', TextType::class, [
                'label' => 'Sujet',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer un sujet.',
                    ]),
                    new Length([
                        'min' => 5,
                        'minMessage' => 'Le sujet doit contenir au moins {{ limit }} caractères.',
                        'max' => 255,
                        'maxMessage' => 'Le sujet ne peut pas dépasser {{ limit }} caractères.',
                    ]),
                ],
                'attr' => ['class' => 'appearance-none block w-full bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded-md py-3 px-4 leading-tight focus:outline-none focus:bg-white dark:focus:bg-gray-800 focus:border-primary dark:focus:border-primary transition duration-200 ease-in-out'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Votre Message',
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer votre message.',
                    ]),
                    new Length([
                        'min' => 10,
                        'minMessage' => 'Votre message doit contenir au moins {{ limit }} caractères.',
                    ]),
                ],
                'attr' => ['class' => 'appearance-none block w-full bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-300 dark:border-gray-600 rounded-md py-3 px-4 leading-tight focus:outline-none focus:bg-white dark:focus:bg-gray-800 focus:border-primary dark:focus:border-primary transition duration-200 ease-in-out h-32 resize-none'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver):
        void
    {
        $resolver->setDefaults([
            // Configure your form options here
        ]);
    }
}