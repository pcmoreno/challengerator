<?php
declare(strict_types=1);

namespace App\Form;

use Gregwar\CaptchaBundle\Type\CaptchaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LoginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('user', TextType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'your username'
                ]
            ])
            ->add('pass', PasswordType::class, [
                'required' => true,
                'attr' => [
                    'placeholder' => 'your password'
                ]
            ])
            ->add('captcha', CaptchaType::class)
            ->add('login', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'login_item'
        ]);
    }
}
