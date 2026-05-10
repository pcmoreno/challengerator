<?php
declare(strict_types=1);

namespace App\Form;

use App\Entity\Challenge\Car;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;

class CarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'fill name of car here'
                ]
            ])
            ->add('imageA', FileType::class, [
                'label'    => 'Image A',
                'mapped'   => false,
                'required' => true,
                'constraints' => [new File(['mimeTypes' => ['image/jpeg', 'image/png', 'image/webp']])],
            ])
            ->add('imageB', FileType::class, [
                'label'    => 'Image B',
                'mapped'   => false,
                'required' => true,
                'constraints' => [new File(['mimeTypes' => ['image/jpeg', 'image/png', 'image/webp']])],
            ])
            ->add('add_car', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Car::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'car_item',
        ]);
    }
}
