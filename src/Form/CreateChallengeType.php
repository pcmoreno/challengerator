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
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class CreateChallengeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('challengeName', TextType::class, [
                    'required' => true,
                    'constraints' => [
                        new Length(['min' => 5]),
                        new Regex(['pattern' => "{^[a-z][a-z0-9_$()+/-]*$}", 'message' => "must be lowercase. Digits and _ $ ( ) + - / are allowed"])
                    ]
                ]
            )
            ->add('adminPass', PasswordType::class, [
                'constraints' => [
                    new Length(['min' => 6]),
                    new NotBlank()
                ]
            ])
            ->add('creationToken', PasswordType::class, [
                'required' => true
            ])
            ->add('create', SubmitType::class)
            ->add('captcha', CaptchaType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id'   => 'create_challenge_item',
        ]);
    }
}