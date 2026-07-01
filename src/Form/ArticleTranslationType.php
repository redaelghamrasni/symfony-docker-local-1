<?php

namespace App\Form;

use App\Entity\ArticleTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ArticleTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('locale', HiddenType::class)
            ->add('title', TextType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['placeholder' => 'article_form.title_placeholder'],
            ])
            ->add('content', TextareaType::class, [
                'label' => false,
                'required' => false,
                'attr' => ['rows' => 12],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ArticleTranslation::class,
        ]);
    }
}
