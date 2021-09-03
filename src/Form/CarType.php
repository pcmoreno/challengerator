<?php
declare(strict_types=1);

namespace App\Form;

use App\Entity\Challenge\Car;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CarType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'fill name of car here'
                ]
            ])
            ->add('imageUrlA', TextType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'imageA from google drive (e.g: 1uHXf1OkiQQWoCILAfAv7Rv5Yp6-uWL3i)'
                ]
            ])
            ->add('imageUrlB', TextType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'imageB from google drive (e.g: 3uHXf1OkiwwWoCILAfAv7Rv5Yp6-uWL69)'
                ]
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