<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Support;

use Nowo\FormKitBundle\Form\Constraint\ConstraintDefinitionFactory;
use Nowo\FormKitBundle\Form\FormOptionsMerger;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\Form\MissingTranslationLogActionType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideFilterType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideResetType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideTextsType;
use Symfony\Component\Form\FormTypeInterface;

/**
 * FormKit wiring for unit tests that build a form factory without the container: the Web UI form types get the
 * {@see FormOptionsMerger} the FormKit compiler pass injects in a kernel, with the bundle's own profile.
 */
final class FormKitTestSupport
{
    public static function merger(): FormOptionsMerger
    {
        $profile = NowoTranslationYamlToolsExtension::FORM_KIT_PROFILE_CONFIG;

        return new FormOptionsMerger(
            [NowoTranslationYamlToolsExtension::FORM_KIT_PROFILE => $profile, 'default' => $profile],
            'default',
            new ConstraintDefinitionFactory(),
        );
    }

    /**
     * @return list<FormTypeInterface<mixed>>
     */
    public static function types(): array
    {
        $merger = self::merger();
        $types  = [
            new MissingTranslationLogActionType(),
            new TranslationOverrideFilterType(),
            new TranslationOverrideResetType(),
            new TranslationOverrideTextsType(),
        ];
        foreach ($types as $type) {
            $type->setFormOptionsMerger($merger);
        }

        return $types;
    }
}
