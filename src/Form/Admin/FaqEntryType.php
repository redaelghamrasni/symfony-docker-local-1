<?php

namespace App\Form\Admin;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class FaqEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['fr', 'en'] as $locale) {
            $builder
                ->add('category_' . $locale, TextType::class, [
                    'label' => 'admin.faq.form.category_label',
                    'constraints' => [new NotBlank()],
                    'attr' => [
                        'placeholder' => 'admin.faq.form.category_placeholder',
                    ],
                ])
                ->add('question_' . $locale, TextareaType::class, [
                    'label' => 'admin.faq.form.question_label',
                    'constraints' => [new NotBlank()],
                    'attr' => [
                        'placeholder' => 'admin.faq.form.question_placeholder',
                        'rows' => 2,
                    ],
                ])
                ->add('answer_' . $locale, TextareaType::class, [
                    'label' => 'admin.faq.form.answer_label',
                    'constraints' => [new NotBlank()],
                    'attr' => [
                        'placeholder' => 'admin.faq.form.answer_placeholder',
                        'rows' => 5,
                    ],
                ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
