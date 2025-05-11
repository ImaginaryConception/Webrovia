<?php

namespace App\Form;

use App\Entity\Prompt;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class PromptType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('content', TextareaType::class, [
                'attr' => [
                    'class' => 'form-control h-32 p-4 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500',
                    'placeholder' => 'Décrivez le site web que vous souhaitez générer...',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer une description pour votre site web',
                    ]),
                    new Length([
                        'min' => 10,
                        'minMessage' => 'Votre description doit faire au moins {{ limit }} caractères',
                        'max' => 1000,
                        'maxMessage' => 'Votre description ne peut pas dépasser {{ limit }} caractères',
                    ]),
                ],
                'label' => false
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Prompt::class,
            'csrf_protection' => false,
        ]);
    }
}