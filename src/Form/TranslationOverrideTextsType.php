<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function str_replace;
use function strtoupper;

/**
 * Edit form of one overridable message: one textarea per locale (children named after the locale, so the
 * request fields are {@code texts[<locale>]} when the form is named {@code texts}). The page puts each textarea in
 * one UiKit locale tab ({@code text_override/edit.html.twig}); the form has no framework classes (REQ-UI-003).
 *
 * Data: map of locale => text. Options: {@code locales} (list of locales, first = default),
 * {@code max_length} (textarea {@code maxlength}; the server-side check lives in
 * {@see TranslationOverrideController::edit()}). Set {@code csrf_token_id} per edited key.
 *
 * @extends AbstractType<array<string, string>>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 */
#[FormKitConfig(NowoTranslationYamlToolsExtension::FORM_KIT_PROFILE)]
final class TranslationOverrideTextsType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * @deprecated since 1.14: the 1.x unmapped CSS-only tab radio group was removed (UiKit locale tabs); kept for BC.
     */
    public const TAB_FIELD = '_tab';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $locales */
        $locales   = $options['locales'];
        $maxLength = (int) $options['max_length'];

        foreach ($locales as $locale) {
            $label = strtoupper($locale);
            $this->addWithDefaults($builder, $locale, TextareaType::class, [
                'label'                        => 'overrides.text_for',
                'label_translation_parameters' => ['%locale%' => $label],
                'required'                     => false,
                'trim'                         => false,
                'empty_data'                   => '',
                'help'                         => 'overrides.text_help',
                'block_prefix'                 => 'nowo_tyt_override_text',
                'row_attr'                     => ['class' => 'nowo-tyt-text-row'],
                'attr'                         => [
                    'rows'      => 4,
                    'maxlength' => $maxLength,
                    'lang'      => str_replace('_', '-', $locale),
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('locales');
        $resolver->setDefaults([
            'max_length'         => 5000,
            'method'             => 'POST',
            'csrf_protection'    => true,
            'translation_domain' => TranslationOverrideController::TRANSLATION_DOMAIN,
            'attr'               => ['novalidate' => 'novalidate'],
        ]);
        $resolver->setAllowedTypes('locales', 'string[]');
        $resolver->setAllowedTypes('max_length', 'int');
    }
}
