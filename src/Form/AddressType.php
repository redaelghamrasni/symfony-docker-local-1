<?php

namespace App\Form;

use App\Entity\Address;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

class AddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('first_name', TextType::class, [
                'label' => 'address_form.first_name_label',
                'constraints' => [
                    new NotBlank(['message' => 'form.first_name_required']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Jean',
                ],
            ])
            ->add('last_name', TextType::class, [
                'label' => 'address_form.last_name_label',
                'constraints' => [
                    new NotBlank(['message' => 'form.last_name_required']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Dupont',
                ],
            ])
            ->add('street', TextType::class, [
                'label' => 'address_form.street_label',
                'constraints' => [
                    new NotBlank(['message' => 'form.street_required']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '123 rue Principale',
                ],
            ])
            ->add('city', TextType::class, [
                'label' => 'address_form.city_label',
                'constraints' => [
                    new NotBlank(['message' => 'form.city_required']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Montréal',
                ],
            ])
            ->add('postal_code', TextType::class, [
                'label' => 'address_form.postal_code_label',
                'constraints' => [
                    new NotBlank(['message' => 'form.postal_code_required']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'H1A 1A1',
                ],
            ])
            ->add('phone', TextType::class, [
                'label' => 'address_form.phone_label',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => '+1 (514) 555-0100',
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'address_form.type_label',
                'choices' => [
                    'address_form.type_shipping' => Address::TYPE_SHIPPING,
                    'address_form.type_billing' => Address::TYPE_BILLING,
                ],
                'choice_translation_domain' => 'messages',
                'attr' => [
                    'class' => 'form-control',
                ],
            ])
            ->add('is_default', CheckboxType::class, [
                'label' => 'address_form.is_default_label',
                'required' => false,
                'attr' => [
                    'class' => 'form-check-input',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Address::class,
        ]);
    }
}
