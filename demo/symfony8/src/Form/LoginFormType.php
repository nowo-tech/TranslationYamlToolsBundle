<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormKitAbstractType;
use Override;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Demo login form (REQ-TWIG-005 rule 8, REQ-DEMO-015 FormKit profile `demo`): the field names form_login reads —
 * `_username`, `_password` and the `_csrf_token` checked with token id `authenticate` (enable_csrf: true).
 *
 * @extends FormKitAbstractType<array<string, mixed>>
 */
#[FormKitConfig('demo')]
final class LoginFormType extends FormKitAbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', TextType::class, [
                'label' => 'Username',
                'data'  => $options['last_username'],
                'attr'  => ['autocomplete' => 'username'],
            ])
            ->add('_password', PasswordType::class, [
                'label' => 'Password',
                'attr'  => ['autocomplete' => 'current-password'],
            ]);
    }

    #[Override]
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id'   => 'authenticate',
            'last_username'   => '',
        ]);
        $resolver->setAllowedTypes('last_username', 'string');
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
