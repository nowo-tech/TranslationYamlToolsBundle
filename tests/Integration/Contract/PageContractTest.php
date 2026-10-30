<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration\Contract;

use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLogStatus;
use Nowo\TranslationYamlToolsBundle\Tests\Support\WebUiPageFixtures;
use Nowo\TranslationYamlToolsBundle\Ui\WebUiPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;

use function dirname;

/**
 * REQ-UI-003 page contract of both opt-in Web UIs (UiKit PageContractTestCase): every page extends a UiKit scaffold
 * (missing-translation log: list; text-overrides desk: list + form), fills the canonical blocks, has no <html>, CDN
 * asset, inline handler or confirm(), prints framework classes only through theme slots, reflects the stub theme, has
 * no raw or missing translation key (strict rule) and keeps the hidden fields (CSRF token, status / confirm markers)
 * of every action form.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        $missingLog = WebUiPages::TEMPLATES[WebUiPages::MISSING_LOG]['index'];
        $overrides  = WebUiPages::TEMPLATES[WebUiPages::OVERRIDES];

        yield 'missing log (pending)' => new PageFixture($missingLog, WebUiPageFixtures::missingLogIndex());
        yield 'missing log (added)' => new PageFixture($missingLog, WebUiPageFixtures::missingLogIndex(MissingTranslationLogStatus::Added));
        yield 'missing log (empty)' => new PageFixture($missingLog, WebUiPageFixtures::missingLogIndex(MissingTranslationLogStatus::Validated, true));
        yield 'texts' => new PageFixture($overrides['index'], WebUiPageFixtures::overridesIndex());
        yield 'texts (no match)' => new PageFixture($overrides['index'], WebUiPageFixtures::overridesIndex(true));
        yield 'text edit (overridden)' => new PageFixture($overrides['edit'], WebUiPageFixtures::overridesEdit());
        yield 'text edit (shipped only)' => new PageFixture($overrides['edit'], WebUiPageFixtures::overridesEdit(false));
        yield 'text edit (invalid)' => new PageFixture($overrides['edit'], WebUiPageFixtures::overridesEdit(true, true));
    }

    protected static function templatePaths(): array
    {
        return ['NowoTranslationYamlToolsBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    protected static function formThemes(): array
    {
        return [WebUiPages::DEFAULT_FORM_THEME];
    }
}
