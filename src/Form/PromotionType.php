<?php

namespace App\Form;

use App\Entity\Article;
use App\Entity\Promotion;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class PromotionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => false,
                'constraints' => [new NotBlank()],
                'attr' => ['placeholder' => 'admin.promotions.form.name_placeholder'],
            ])
            ->add('description', TextareaType::class, [
                'label' => false,
                'required' => false,
                'attr' => [
                    'placeholder' => 'admin.promotions.form.description_placeholder',
                    'rows' => 3,
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => false,
                'choices' => [
                    'admin.promotions.type.percent_off' => Promotion::TYPE_PERCENT_OFF,
                    'admin.promotions.type.amount_off'  => Promotion::TYPE_AMOUNT_OFF,
                    'admin.promotions.type.fixed_price' => Promotion::TYPE_FIXED_PRICE,
                ],
                'choice_translation_domain' => 'messages',
            ])
            ->add('value', NumberType::class, [
                'label' => false,
                'scale' => 2,
                'html5' => true,
                'attr' => ['placeholder' => '0.00', 'step' => '0.01', 'min' => '0'],
            ])
            ->add('startsAt', DateTimeType::class, [
                'label' => false,
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => false,
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => false,
                'required' => false,
            ])
            ->add('articles', EntityType::class, [
                'class' => Article::class,
                'choice_label' => 'title',
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => false,
                'attr' => ['size' => 8],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Promotion::class,
        ]);
    }
}
