<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Form;

use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SearchType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function sprintf;

/**
 * GET search / group filter of the text-overrides desk. Created with an empty name by
 * {@see TranslationOverrideController} so the query parameters stay {@code q} and {@code group}
 * (bookmarks and the "Back to texts" links keep working). No CSRF: it only filters.
 *
 * Option {@code groups}: map of group name => number of editable messages in it.
 *
 * @extends AbstractType<array<string, mixed>|null>
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 */
final class TranslationOverrideFilterType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, int> $groups */
        $groups  = $options['groups'];
        $choices = [];
        foreach ($groups as $name => $count) {
            $choices[sprintf('%s (%d)', $name, $count)] = (string) $name;
        }

        $builder
            ->add('q', SearchType::class, [
                'label'    => 'overrides.search',
                'required' => false,
            ])
            ->add('group', ChoiceType::class, [
                'label'                     => 'overrides.group',
                'required'                  => false,
                'choices'                   => $choices,
                'choice_translation_domain' => false,
                'placeholder'               => 'overrides.all_groups',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'groups'             => [],
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => TranslationOverrideController::TRANSLATION_DOMAIN,
        ]);
        $resolver->setAllowedTypes('groups', 'array');
    }
}
