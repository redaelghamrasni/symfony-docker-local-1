<?php

namespace App\Form\Admin;

use App\Entity\MarketRegion;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class MarketRegionType extends AbstractType
{
    // The app's form-control / form-label classes (assets/styles/custom.css)
    // give the admin inputs their boxed, spaced look — the default form theme
    // renders them unstyled.
    private const LABEL = ['class' => 'form-label'];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('country', TextType::class, [
                'label'       => 'admin.regions.form.country',
                'label_attr'  => self::LABEL,
                'attr'        => ['class' => 'form-control', 'maxlength' => 2, 'placeholder' => 'US', 'style' => 'text-transform:uppercase'],
                'help'        => 'admin.regions.form.country_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(['pattern' => '/^[A-Za-z]{2}$/', 'message' => 'admin.regions.form.country_invalid']),
                ],
            ])
            ->add('code', TextType::class, [
                'label'       => 'admin.regions.form.code',
                'label_attr'  => self::LABEL,
                'attr'        => ['class' => 'form-control', 'maxlength' => 10, 'placeholder' => 'CA', 'style' => 'text-transform:uppercase'],
                'help'        => 'admin.regions.form.code_help',
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(['pattern' => '/^[A-Za-z0-9-]{1,10}$/', 'message' => 'admin.regions.form.code_invalid']),
                ],
            ])
            ->add('name', TextType::class, [
                'label'       => 'admin.regions.form.name',
                'label_attr'  => self::LABEL,
                'attr'        => ['class' => 'form-control', 'placeholder' => 'California'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('position', IntegerType::class, [
                'label'      => 'admin.regions.form.position',
                'label_attr' => self::LABEL,
                'attr'       => ['class' => 'form-control'],
                'required'   => false,
                'help'       => 'admin.regions.form.position_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MarketRegion::class]);
    }
}
