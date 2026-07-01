<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ForgotPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'auth.forgot_password.email_label',
                'translation_domain' => 'messages',
                'constraints' => [
                    new Assert\NotBlank(['message' => 'form.email_required']),
                    new Assert\Email(['message' => 'form.email_invalid']),
                ],
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'vous@exemple.com',
                    'autocomplete' => 'email',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
