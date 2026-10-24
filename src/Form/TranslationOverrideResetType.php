<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Form;

use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Restore shipped text" form of the text-overrides desk: a required confirmation checkbox (no JavaScript
 * confirm; {@see TranslationOverrideController::reset()} checks it server-side too).
 *
 * Option {@code overridden_locales}: label of the overridden locales shown in the confirmation text.
 * Set {@code csrf_token_id} per edited key.
 *
 * @extends AbstractType<array<string, mixed>|null>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 */
final class TranslationOverrideResetType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirm', CheckboxType::class, [
            'label'                        => 'overrides.reset_confirm',
            'label_translation_parameters' => ['%locales%' => $options['overridden_locales']],
            'required'                     => true,
            'row_attr'                     => ['class' => 'mb-2'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'overridden_locales' => '',
            'method'             => 'POST',
            'csrf_protection'    => true,
            'translation_domain' => TranslationOverrideController::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('overridden_locales', 'string');
    }
}
