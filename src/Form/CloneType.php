<?php

namespace App\Form;

use App\Entity\WebsiteClone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Url;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\UrlType;

class CloneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('sourceUrl', UrlType::class, [
                'attr' => [
                    'class' => 'form-control h-12 p-4 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500',
                    'placeholder' => 'Entrez l\'URL du site à cloner...',
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer l\'URL du site à cloner',
                    ]),
                    new Url([
                        'message' => 'Veuillez entrer une URL valide',
                    ]),
                ],
                'label' => false
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => WebsiteClone::class,
            'csrf_protection' => false,
        ]);
    }
}