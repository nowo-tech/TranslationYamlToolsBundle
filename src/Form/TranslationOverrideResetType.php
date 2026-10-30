<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * "Restore shipped text" form of the text-overrides desk: a CSRF-only POST form with the hidden {@code confirm}
 * marker ({@code 1}). The page renders it as the UiKit delete-zone action behind the UiKit confirm dialog (no inline
 * {@code confirm()}); {@see TranslationOverrideController::reset()} still refuses a POST without the marker.
 *
 * Option {@code overridden_locales}: kept for BC (1.x confirmation checkbox label); no longer rendered.
 * Set {@code csrf_token_id} per edited key.
 *
 * @extends AbstractType<array<string, mixed>|null>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 */
#[FormKitConfig(NowoTranslationYamlToolsExtension::FORM_KIT_PROFILE)]
final class TranslationOverrideResetType extends AbstractType
{
    use FormOptionsTrait;

    /** Value of the hidden {@code confirm} marker. */
    public const CONFIRMED = '1';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->addWithDefaults($builder, 'confirm', HiddenType::class, [
            'data' => self::CONFIRMED,
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
