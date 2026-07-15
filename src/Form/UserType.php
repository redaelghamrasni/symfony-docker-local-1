<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['data'] && $options['data']->getId();

        $builder
            ->add('email', EmailType::class, [
                'label' => 'form.email',
                'translation_domain' => 'messages',
                'constraints' => [
                    new NotBlank(['message' => 'form.email_required']),
                    new Email(['message' => 'form.email_invalid']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'your@email.com',
                ],
            ])
            ->add('username', TextType::class, [
                'label' => 'form.username',
                'translation_domain' => 'messages',
                'constraints' => [
                    new NotBlank(['message' => 'form.username_required']),
                    new Length(['min' => 3, 'max' => 180, 'minMessage' => 'form.username_min', 'maxMessage' => 'form.username_max']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'johndoe',
                ],
            ])
            ->add('first_name', TextType::class, [
                'label' => 'form.first_name',
                'translation_domain' => 'messages',
                'constraints' => [
                    new NotBlank(['message' => 'form.first_name_required']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'John',
                ],
            ])
            ->add('last_name', TextType::class, [
                'label' => 'form.last_name',
                'translation_domain' => 'messages',
                'constraints' => [
                    new NotBlank(['message' => 'form.last_name_required']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Doe',
                ],
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'form.language',
                'translation_domain' => 'messages',
                'choices' => [
                    'Français' => 'fr',
                    'English' => 'en',
                ],
                'attr' => [
                    'class' => 'form-control',
                ],
            ]);

        if (!$isEdit) {
            $builder->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'form.password',
                    'translation_domain' => 'messages',
                    'constraints' => [
                        new NotBlank(['message' => 'form.password_required']),
                        new Length(['min' => 8, 'minMessage' => 'form.password_min']),
                    ],
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => $this->translator->trans('form.password_placeholder'),
                    ],
                ],
                'second_options' => [
                    'label' => 'form.confirm_password',
                    'translation_domain' => 'messages',
                    'attr' => [
                        'class' => 'form-control',
                        'placeholder' => $this->translator->trans('form.confirm_password_placeholder'),
                    ],
                ],
                'invalid_message' => 'form.passwords_match',
            ]);
        } else {
            $builder->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'first_options' => [
                    'label' => 'form.new_password',
                    'translation_domain' => 'messages',
                    'constraints' => [
                        new Length(['min' => 8, 'minMessage' => 'form.password_min']),
                    ],
                    'attr' => [
                        'class' => 'form-control',
                        'autocomplete' => 'new-password',
                        'placeholder' => $this->translator->trans('form.new_password_placeholder'),
                    ],
                ],
                'second_options' => [
                    'label' => 'form.confirm_new_password',
                    'translation_domain' => 'messages',
                    'attr' => [
                        'class' => 'form-control',
                        'autocomplete' => 'new-password',
                        'placeholder' => $this->translator->trans('form.confirm_new_password_placeholder'),
                    ],
                ],
                'invalid_message' => 'form.passwords_match',
            ]);
        }

        if ($isEdit) {
            $builder->add('autoFillCheckout', CheckboxType::class, [
                'label' => false,
                'required' => false,
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
