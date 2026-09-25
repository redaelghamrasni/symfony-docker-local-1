<?php

namespace App\Form\Admin;

use App\Entity\Currency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CurrencyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isDefault = $options['is_default'];

        $builder
            ->add('code', TextType::class, [
                'label' => 'admin.currencies.form.code',
                'attr'  => ['maxlength' => 3, 'placeholder' => 'USD', 'style' => 'text-transform:uppercase'],
                'help'  => 'admin.currencies.form.code_help',
                // The code identifies the currency to Stripe and PayPal, and
                // article prices hang off it — changing it later would orphan
                // them, so it is fixed once the currency exists.
                'disabled' => $options['is_edit'],
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Regex(['pattern' => '/^[A-Za-z]{3}$/', 'message' => 'admin.currencies.form.code_invalid']),
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'admin.currencies.form.name',
                'attr'  => ['placeholder' => 'US dollar'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('symbol', TextType::class, [
                'label' => 'admin.currencies.form.symbol',
                'attr'  => ['maxlength' => 8, 'placeholder' => '$'],
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('symbolPosition', ChoiceType::class, [
                'label'   => 'admin.currencies.form.symbol_position',
                'choices' => [
                    'admin.currencies.form.before' => 'before',
                    'admin.currencies.form.after'  => 'after',
                ],
            ])
            ->add('exchangeRate', NumberType::class, [
                'label'    => 'admin.currencies.form.exchange_rate',
                'scale'    => 6,
                'help'     => $isDefault
                    ? 'admin.currencies.form.rate_help_default'
                    : 'admin.currencies.form.rate_help',
                // The reference currency is 1 by definition.
                'disabled' => $isDefault,
                'constraints' => [
                    new Assert\NotBlank(),
                    new Assert\Positive(message: 'admin.currencies.form.rate_positive'),
                ],
            ])
            ->add('enabled', CheckboxType::class, [
                'label'    => 'admin.currencies.form.enabled',
                'required' => false,
                'help'     => $isDefault
                    ? 'admin.currencies.form.enabled_help_default'
                    : 'admin.currencies.form.enabled_help',
                // The shop must always have one usable currency.
                'disabled' => $isDefault,
            ])
            ->add('position', IntegerType::class, [
                'label'    => 'admin.currencies.form.position',
                'required' => false,
                'help'     => 'admin.currencies.form.position_help',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Currency::class,
            'is_edit'    => false,
            'is_default' => false,
        ]);

        $resolver->setAllowedTypes('is_edit', 'bool');
        $resolver->setAllowedTypes('is_default', 'bool');
    }
}
