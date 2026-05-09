<?php
declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AdminDeleteVoterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('voterToDelete', TextType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'uuid of the voter you want to delete'
                ]
            ])
            ->add('adminPass', PasswordType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'enter your admin password here'
                ]
            ])
            ->add('delete', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'delete_voter_item',
        ]);
    }
}