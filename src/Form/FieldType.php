<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Regex;

class FieldType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fieldName', TextType::class, [
                'label' => 'Nom du champ',
                'required' => true,
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer un nom pour le champ',
                    ]),
                    new Length([
                        'min' => 1,
                        'minMessage' => 'Le nom doit contenir au moins {{ limit }} caractère',
                        'max' => 64,
                        'maxMessage' => 'Le nom ne peut pas dépasser {{ limit }} caractères',
                    ]),
                    new Regex([
                        'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/',
                        'message' => 'Le nom du champ doit commencer par une lettre et ne contenir que des lettres, des chiffres et des underscores',
                    ]),
                ],
            ])
            ->add('fieldType', ChoiceType::class, [
                'label' => 'Type du champ',
                'required' => true,
                'choices' => [
                    'Texte court' => 'string',
                    'Texte long' => 'text',
                    'Nombre entier' => 'integer',
                    'Nombre décimal' => 'float',
                    'Booléen' => 'boolean',
                    'Date et heure' => 'datetime',
                    'Date' => 'date',
                    'Heure' => 'time',
                    'Email' => 'email',
                    'URL' => 'url',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez sélectionner un type pour le champ',
                    ]),
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