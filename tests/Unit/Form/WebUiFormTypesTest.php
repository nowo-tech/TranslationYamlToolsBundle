<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\Form;

use Nowo\TranslationYamlToolsBundle\Form\MissingTranslationLogActionType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideFilterType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideResetType;
use Nowo\TranslationYamlToolsBundle\Form\TranslationOverrideTextsType;
use Nowo\TranslationYamlToolsBundle\Tests\Support\FormKitTestSupport;
use Nowo\TranslationYamlToolsBundle\TextOverride\Controller\TranslationOverrideController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

#[CoversClass(MissingTranslationLogActionType::class)]
#[CoversClass(TranslationOverrideFilterType::class)]
#[CoversClass(TranslationOverrideTextsType::class)]
#[CoversClass(TranslationOverrideResetType::class)]
final class WebUiFormTypesTest extends TypeTestCase
{
    /**
     * @return array<FormTypeInterface<mixed>>
     */
    protected function getTypes(): array
    {
        return [...parent::getTypes(), ...FormKitTestSupport::types()];
    }

    public function testActionTypeWithoutStatusHasNoField(): void
    {
        $form = $this->factory->createNamed('missing_log_mark_added_7', MissingTranslationLogActionType::class);

        self::assertCount(0, $form);
        self::assertSame('POST', $form->getConfig()->getMethod());
        self::assertTrue($form->getConfig()->getOption('csrf_protection'));
        self::assertSame('_token', $form->getConfig()->getOption('csrf_field_name'));
    }

    public function testActionTypeCarriesTheStatusAsHiddenField(): void
    {
        $form = $this->factory->createNamed('missing_log_clear', MissingTranslationLogActionType::class, null, ['status' => 'added']);

        self::assertInstanceOf(HiddenType::class, $form->get('status')->getConfig()->getType()->getInnerType());
        self::assertSame('added', $form->get('status')->getData());

        $form->submit(['status' => 'pending']);
        self::assertTrue($form->isSynchronized());
        self::assertSame('pending', $form->get('status')->getData());
    }

    public function testActionTypeRejectsNonStringStatus(): void
    {
        $this->expectException(InvalidOptionsException::class);
        $this->factory->create(MissingTranslationLogActionType::class, null, ['status' => 3]);
    }

    public function testFilterTypeListsGroupsWithCounts(): void
    {
        $form = $this->factory->createNamed('', TranslationOverrideFilterType::class, null, [
            'groups' => ['site.topline' => 2, 'ShopBundle:loader' => 1],
        ]);

        self::assertSame('GET', $form->getConfig()->getMethod());
        self::assertFalse($form->getConfig()->getOption('csrf_protection'));
        self::assertSame(TranslationOverrideController::TRANSLATION_DOMAIN, $form->getConfig()->getOption('translation_domain'));

        $view    = $form->createView();
        $choices = array_map(static fn ($choice): array => [$choice->label, $choice->value], $view['group']->vars['choices']);
        self::assertSame([['site.topline (2)', 'site.topline'], ['ShopBundle:loader (1)', 'ShopBundle:loader']], array_values($choices));
        self::assertSame('overrides.all_groups', $view['group']->vars['placeholder']);
        self::assertSame('q', $view['q']->vars['full_name']);
        self::assertSame([], $view['q']->vars['attr'], 'Placeholder and layout come from the UiKit filters region (FilterField).');
        self::assertSame([], $view['group']->vars['row_attr'], 'No framework grid classes (REQ-UI-003).');

        $form->submit(['q' => '  hours ', 'group' => 'site.topline']);
        self::assertSame('hours', $form->get('q')->getData());
        self::assertSame('site.topline', $form->get('group')->getData());
    }

    public function testFilterTypeKeepsUnknownGroupAsViewData(): void
    {
        $form = $this->factory->createNamed('', TranslationOverrideFilterType::class, null, ['groups' => ['site' => 1]]);
        $form->submit(['group' => 'gone']);

        self::assertFalse($form->get('group')->isSynchronized());
        self::assertSame('gone', $form->get('group')->getViewData());
    }

    public function testTextsTypeHasOneTextareaPerLocale(): void
    {
        $form = $this->factory->createNamed('texts', TranslationOverrideTextsType::class, ['en' => 'Open', 'pt_BR' => 'Aberto'], [
            'locales'    => ['en', 'pt_BR'],
            'max_length' => 120,
        ]);

        self::assertSame(['en', 'pt_BR'], array_keys($form->all()), 'No 1.x CSS-only tab field: the page uses the UiKit locale tabs.');
        self::assertInstanceOf(TextareaType::class, $form->get('pt_BR')->getConfig()->getType()->getInnerType());

        $view = $form->createView();
        self::assertSame('texts[pt_BR]', $view['pt_BR']->vars['full_name']);
        self::assertSame(['rows' => 4, 'maxlength' => 120, 'lang' => 'pt-BR'], $view['pt_BR']->vars['attr']);
        self::assertSame([], $view['pt_BR']->vars['label_attr'], 'No framework classes (REQ-UI-003).');
        self::assertSame(['%locale%' => 'PT_BR'], $view['pt_BR']->vars['label_translation_parameters']);
        self::assertContains('nowo_tyt_override_text', $view['en']->vars['block_prefixes']);
        self::assertSame('Aberto', $view['pt_BR']->vars['value']);

        $form->submit(['en' => '  spaced  ']);
        self::assertTrue($form->isSynchronized());
        self::assertSame(['en' => '  spaced  ', 'pt_BR' => ''], $form->getData(), 'No trimming; a missing locale is cleared.');

    }

    public function testTextsTypeRequiresLocales(): void
    {
        $this->expectException(MissingOptionsException::class);
        $this->factory->create(TranslationOverrideTextsType::class);
    }

    public function testResetTypeIsACsrfFormWithAHiddenConfirmMarker(): void
    {
        $form = $this->factory->create(TranslationOverrideResetType::class, null, ['overridden_locales' => 'EN, ES']);

        self::assertSame('translation_override_reset', $form->getName());
        self::assertSame('POST', $form->getConfig()->getMethod());
        self::assertInstanceOf(HiddenType::class, $form->get('confirm')->getConfig()->getType()->getInnerType());
        self::assertSame(TranslationOverrideResetType::CONFIRMED, $form->createView()['confirm']->vars['value']);

        $form->submit(['confirm' => TranslationOverrideResetType::CONFIRMED]);
        self::assertSame(TranslationOverrideResetType::CONFIRMED, $form->get('confirm')->getData());

        $forged = $this->factory->create(TranslationOverrideResetType::class);
        $forged->submit(['confirm' => '']);
        self::assertNotSame(TranslationOverrideResetType::CONFIRMED, $forged->get('confirm')->getData());
    }
}
