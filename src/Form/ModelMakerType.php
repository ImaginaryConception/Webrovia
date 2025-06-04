<?php

namespace App\Form;

use App\Entity\ModelMaker;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class ModelMakerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre de la maquette',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Donnez un titre à votre maquette',
                    'class' => 'appearance-none block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent dark:bg-gray-700 dark:text-white sm:text-sm transition-all duration-300 hover:border-purple-400 dark:hover:border-purple-400'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer un titre pour votre maquette',
                    ]),
                    new Length([
                        'min' => 3,
                        'minMessage' => 'Le titre doit contenir au moins {{ limit }} caractères',
                        'max' => 255,
                        'maxMessage' => 'Le titre ne peut pas dépasser {{ limit }} caractères',
                    ]),
                ],
            ])
            ->add('prompt', TextareaType::class, [
                'label' => 'Description de la maquette',
                'required' => true,
                'attr' => [
                    'placeholder' => 'Décrivez en détail la maquette que vous souhaitez générer (type de site, style, couleurs, éléments importants...)',
                    'rows' => 6,
                    'class' => 'appearance-none block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent dark:bg-gray-700 dark:text-white sm:text-sm transition-all duration-300 hover:border-purple-400 dark:hover:border-purple-400'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer une description pour votre maquette',
                    ]),
                    new Length([
                        'min' => 10,
                        'minMessage' => 'La description doit contenir au moins {{ limit }} caractères',
                        'max' => 1000,
                        'maxMessage' => 'La description ne peut pas dépasser {{ limit }} caractères',
                    ]),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ModelMaker::class,
        ]);
    }
}