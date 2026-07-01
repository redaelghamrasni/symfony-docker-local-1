<?php

namespace App\Form;

use App\Entity\Article;
use App\Entity\Category;
use App\Entity\Promotion;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class ArticleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, [
                'label' => 'Titre',
                'attr' => [
                    'placeholder' => 'Entrez le titre',
                    'class' => 'form-control',
                ],
            ])
            ->add('content', TextareaType::class, [
                'label' => 'Contenu',
                'attr' => [
                    'rows' => 10,
                    'class' => 'form-control',
                ],
            ])
            ->add('translations', CollectionType::class, [
                'entry_type'   => ArticleTranslationType::class,
                'allow_add'    => false,
                'allow_delete' => false,
                'by_reference' => false,
                'label'        => false,
            ])
            ->add('category', EntityType::class, [
                'class'        => Category::class,
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => 'article_form.category_placeholder',
                'label'        => 'article_form.category_label',
            ])
            ->add('price', NumberType::class, [
                'label'  => 'Price',
                'scale'  => 2,
                'html5'  => true,
                'attr'   => ['placeholder' => '0.00', 'step' => '0.01', 'min' => '0'],
            ])
            ->add('promotions', EntityType::class, [
                'class'        => Promotion::class,
                'choice_label' => function (Promotion $p): string {
                    $typeLabel = match ($p->getType()) {
                        Promotion::TYPE_PERCENT_OFF => '%',
                        Promotion::TYPE_AMOUNT_OFF  => '$ déduit',
                        Promotion::TYPE_FIXED_PRICE => '$ fixe',
                        default                     => '',
                    };
                    $status = $p->isCurrentlyActive() ? '✓' : '○';
                    return sprintf('%s %s — %s %s', $status, $p->getName(), $p->getValue(), $typeLabel);
                },
                'multiple'  => true,
                'expanded'  => false,
                'required'  => false,
                'label'     => false,
                'attr'      => ['size' => 6],
            ])
            ->add('imageFile', FileType::class, [
                'label'    => false,
                'mapped'   => false,
                'required' => false,
                'constraints' => [
                    new File([
                        'maxSize'   => '4096k',
                        'mimeTypes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
                    ]),
                ],
            ])
            ->add('deleteImage', CheckboxType::class, [
                'label'    => false,
                'mapped'   => false,
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Article::class,
        ]);
    }
}
