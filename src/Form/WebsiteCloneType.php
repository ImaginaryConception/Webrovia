<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\Regex;

class WebsiteCloneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('website_url', UrlType::class, [
                'label' => 'URL du site web',
                'required' => true,
                'attr' => [
                    'placeholder' => 'https://example.com',
                    'class' => 'block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-50 dark:bg-gray-700 dark:text-white'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer une URL'
                    ]),
                    new Url([
                        'message' => 'Veuillez entrer une URL valide'
                    ]),
                    new Regex([
                        'pattern' => '/^https?:\/\//',
                        'message' => 'L\'URL doit commencer par http:// ou https://'
                    ])
                ]
            ])
            ->add('project_name', TextType::class, [
                'label' => 'Nom du projet',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Mon super site',
                    'class' => 'block w-full rounded-md border-gray-300 dark:border-gray-600 shadow-sm focus:border-primary focus:ring focus:ring-primary focus:ring-opacity-50 dark:bg-gray-700 dark:text-white'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer un nom de projet'
                    ])
                ]
            ])
            ->add('clone_type', ChoiceType::class, [
                'label' => 'Type de clonage',
                'required' => true,
                'choices' => [
                    'Page unique' => 'single',
                    'Site complet (avec sous-pages)' => 'complete'
                ],
                'expanded' => true,
                'multiple' => false,
                'attr' => [
                    'class' => 'space-y-2'
                ],
                'choice_attr' => function($choice, $key, $value) {
                    return ['class' => 'rounded border-gray-300 dark:border-gray-600 text-primary focus:ring-primary dark:bg-gray-700'];
                }
            ])
            ->add('include_assets', CheckboxType::class, [
                'label' => 'Inclure les ressources (images, fichiers, etc.)',
                'required' => false,
                'attr' => [
                    'class' => 'rounded border-gray-300 dark:border-gray-600 text-primary focus:ring-primary dark:bg-gray-700'
                ]
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