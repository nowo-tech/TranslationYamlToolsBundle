<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Form;

use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function str_replace;
use function strtoupper;

/**
 * Edit form of one overridable message: one textarea per locale (children named after the locale, so the
 * request fields are {@code texts[<locale>]} when the form is named {@code texts}) plus the unmapped
 * {@code _tab} radio group that drives the CSS-only locale tabs (rendered by the
 * {@code nowo_tyt_locale_tabs_widget} block of {@code text_override/form_theme.html.twig}).
 *
 * Data: map of locale => text. Options: {@code locales} (list of locales, first = default),
 * {@code max_length} (textarea {@code maxlength}; the server-side check lives in
 * {@see TranslationOverrideController::edit()}). Set {@code csrf_token_id} per edited key.
 *
 * @extends AbstractType<array<string, string>>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 */
final class TranslationOverrideTextsType extends AbstractType
{
    public const TAB_FIELD = '_tab';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $locales */
        $locales   = $options['locales'];
        $maxLength = (int) $options['max_length'];
        $tabs      = [];

        foreach ($locales as $locale) {
            $label        = strtoupper($locale);
            $tabs[$label] = $locale;
            $builder->add($locale, TextareaType::class, [
                'label'                        => 'overrides.text_for',
                'label_translation_parameters' => ['%locale%' => $label],
                'label_attr'                   => ['class' => 'form-label'],
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

        $builder->add(self::TAB_FIELD, ChoiceType::class, [
            'mapped'                    => false,
            'expanded'                  => true,
            'multiple'                  => false,
            'required'                  => false,
            'label'                     => false,
            'choices'                   => $tabs,
            'choice_translation_domain' => false,
            'placeholder'               => false,
            'data'                      => $locales[0] ?? null,
            'block_prefix'              => 'nowo_tyt_locale_tabs',
        ]);
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
