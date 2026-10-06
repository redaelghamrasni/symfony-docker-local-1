<?php

namespace App\Form\Admin;

use App\Entity\TaxRate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class TaxRateType extends AbstractType
{
    // The app's form-control / form-label classes (assets/styles/custom.css)
    // give the admin inputs their boxed, spaced look — the default form theme
    // renders them unstyled.
    private const LABEL = ['class' => 'form-label'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Rates are entered as percentages (7, 9.975) and stored as fractions;
        // the controller does the /100 and *100 so admins never type "0.09975".
        $percent = [
            'label_attr' => self::LABEL,
            'scale'      => 5,
            'help'       => 'admin.taxes.form.percent_help',
            'attr'       => ['class' => 'form-control', 'step' => 'any', 'min' => 0, 'max' => 100],
            'constraints' => [
                new Assert\NotNull(),
                new Assert\Range(min: 0, max: 100, notInRangeMessage: 'admin.taxes.form.range'),
            ],
        ];

        $builder
            ->add('province', TextType::class, [
                'label'      => 'admin.taxes.form.province',
                'label_attr' => self::LABEL,
                'attr'       => ['class' => 'form-control', 'maxlength' => 5, 'placeholder' => 'QC', 'style' => 'text-transform:uppercase'],
                'help'       => 'admin.taxes.form.province_help',
                'disabled'   => $options['is_edit'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(['pattern' => '/^[A-Za-z]{2,5}$/', 'message' => 'admin.taxes.form.province_invalid']),
                ],
            ])
            ->add('name', TextType::class, [
                'label'       => 'admin.taxes.form.name',
                'label_attr'  => self::LABEL,
                'attr'        => ['class' => 'form-control', 'placeholder' => 'Quebec'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('gstPercent', NumberType::class, ['label' => 'admin.taxes.form.gst'] + $percent)
            ->add('pstPercent', NumberType::class, ['label' => 'admin.taxes.form.pst'] + $percent)
            ->add('hstPercent', NumberType::class, ['label' => 'admin.taxes.form.hst'] + $percent);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Not bound to the entity: the entity stores fractions, the form shows
        // percentages, so the controller maps between them.
        $resolver->setDefaults([
            'data_class' => null,
            'is_edit'    => false,
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
    }
}
