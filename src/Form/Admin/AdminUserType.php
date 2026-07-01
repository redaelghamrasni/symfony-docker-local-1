<?php

namespace App\Form\Admin;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AdminUserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'admin.users.email',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'form.email_required']),
                    new Assert\Email(['message' => 'form.email_invalid']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'user@example.com',
                ],
            ])
            ->add('username', TextType::class, [
                'label' => 'admin.users.username',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'form.username_required']),
                    new Assert\Length(['min' => 3, 'max' => 180, 'minMessage' => 'form.username_min', 'maxMessage' => 'form.username_max']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'username',
                ],
            ])
            ->add('first_name', TextType::class, [
                'label' => 'admin.users.first_name',
                'constraints' => [new Assert\NotBlank()],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'John',
                ],
            ])
            ->add('last_name', TextType::class, [
                'label' => 'admin.users.last_name',
                'constraints' => [new Assert\NotBlank()],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Doe',
                ],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'required' => false,
                'first_options' => [
                    'label' => 'admin.users.plainPassword.first',
                    'translation_domain' => 'messages',
                    'attr' => [
                        'class' => 'form-control',
                        'autocomplete' => 'new-password',
                    ],
                ],
                'second_options' => [
                    'label' => 'admin.users.plainPassword.second',
                    'translation_domain' => 'messages',
                    'attr' => [
                        'class' => 'form-control',
                        'autocomplete' => 'new-password',
                    ],
                ],
                'invalid_message' => 'form.passwords_match',
                'constraints' => [
                    new Assert\Length([
                        'min' => 6,
                        'max' => 100,
                        'minMessage' => 'form.password_min',
                    ]),
                ],
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'admin.users.roles',
                'choices' => [
                    'User' => 'ROLE_USER',
                    'Admin' => 'ROLE_ADMIN',
                ],
                'multiple' => true,
                'expanded' => true,
                'attr' => [
                    'class' => 'form-check',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
