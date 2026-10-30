<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration\Contract;

use InvalidArgumentException;
use Nowo\TranslationYamlToolsBundle\Tests\Support\WebUiPageFixtures;
use Nowo\TranslationYamlToolsBundle\Ui\WebUiPages;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

use function dirname;
use function restore_error_handler;
use function set_error_handler;
use function substr_count;

use const E_USER_DEPRECATED;

/**
 * Deprecated 1.x layouts (no UiKit canonical blocks) keep working through the bridges: the missing-log page renders
 * in `nowo_translation_yaml_tools_missing_log_content` (the 1.x block of layout_integrate_*.html.twig), the overrides
 * pages in the Symfony `body` block, once, with title, assets, flashes and scripts mapped. A layout with the canonical
 * blocks is used as is; the former bundle default layouts mean "UiKit layout".
 */
#[CoversClass(WebUiPages::class)]
final class LegacyLayoutBridgeTest extends TestCase
{
    public const LAYOUTS = [
        // A missing-log layout written for 1.x (the blocks of layout_integrate_dashboard_menu.html.twig)
        'legacy/missing-log.html.twig' => '<header data-host="missing-log"><title>{% block missing_log_title %}{% endblock %}</title>{% block missing_log_head %}{% endblock %}</header>'
            . '<main data-host-main>{% block nowo_translation_yaml_tools_missing_log_content %}{% endblock %}</main>{% block missing_log_scripts %}{% endblock %}',
        // An overrides layout written for 1.x (Symfony base.html.twig convention)
        'legacy/base.html.twig' => '<header data-host="base"><title>{% block title %}{% endblock %}</title>{% block stylesheets %}<link data-host-css>{% endblock %}</header>'
            . '<main data-host-main>{% block body %}{% endblock %}</main>{% block javascripts %}<script data-host-js></script>{% endblock %}',
        // A host layout that already renders the UiKit canonical blocks
        'canonical.html.twig' => '<div data-host-canonical><title>{% block nowo_ui_document_title %}{% endblock %}</title>{% block nowo_ui_page_header %}{% endblock %}'
            . '{% block nowo_ui_flashes %}{% endblock %}{% block nowo_ui_content %}{% endblock %}{% block nowo_ui_modals %}{% endblock %}</div>',
    ];

    #[Test]
    public function aLegacyMissingLogLayoutRendersThePageInItsContentBlock(): void
    {
        $twig  = $this->twig();
        $pages = new WebUiPages(WebUiPages::MISSING_LOG, 'legacy/missing-log.html.twig', [], [], $twig);

        $deprecations = $this->deprecations(static fn (): ?string => $pages->layout());
        self::assertSame(WebUiPages::LEGACY_LAYOUT_BRIDGES[WebUiPages::MISSING_LOG], $deprecations['result']);
        self::assertStringContainsString('"legacy/missing-log.html.twig" does not render the UiKit canonical blocks', $deprecations['messages'][0] ?? '');
        $context = $this->deprecations(static fn (): array => WebUiPageFixtures::missingLogIndex(pages: $pages))['result'];
        self::assertSame('legacy/missing-log.html.twig', $context[WebUiPages::LEGACY_LAYOUT_VARIABLE]);

        $html = $twig->render(WebUiPages::TEMPLATES[WebUiPages::MISSING_LOG]['index'], $context);

        self::assertSame(1, substr_count($html, 'data-host="missing-log"'));
        self::assertMatchesRegularExpression('/<main data-host-main>.*data-testid="tyt-missing-log"/s', $html);
        self::assertSame(1, substr_count($html, 'data-testid="tyt-missing-log"'));
        self::assertStringContainsString('<title>Missing translation log</title>', $html);
        self::assertStringContainsString('nowo-ui.css', $html);
        self::assertStringContainsString('data-nowo-ui-contract-partial="page_header"', $html);
        self::assertStringContainsString('<dialog', $html, 'Confirm dialogs are rendered by the bridge (nowo_ui_modals).');
    }

    #[Test]
    public function aLegacyBaseLayoutRendersTheOverridesPagesInItsBodyBlock(): void
    {
        $twig  = $this->twig();
        $pages = new WebUiPages(WebUiPages::OVERRIDES, 'legacy/base.html.twig', [], [WebUiPages::DEFAULT_FORM_THEME], $twig);

        $deprecations = $this->deprecations(static fn (): ?string => $pages->layout());
        self::assertSame(WebUiPages::LEGACY_LAYOUT_BRIDGES[WebUiPages::OVERRIDES], $deprecations['result']);
        self::assertStringContainsString('"legacy/base.html.twig" does not render the UiKit canonical blocks', $deprecations['messages'][0] ?? '');

        $contexts = $this->deprecations(static fn (): array => [
            'index' => WebUiPageFixtures::overridesIndex(pages: $pages),
            'edit'  => WebUiPageFixtures::overridesEdit(pages: $pages),
        ])['result'];
        foreach ($contexts as $page => $context) {
            $html = $twig->render(WebUiPages::TEMPLATES[WebUiPages::OVERRIDES][$page], $context);

            self::assertSame(1, substr_count($html, 'data-host="base"'), $page);
            self::assertMatchesRegularExpression('/<main data-host-main>.*data-testid="tyt-overrides-' . $page . '"/s', $html);
            self::assertStringContainsString('data-host-css', $html);
            self::assertStringContainsString('nowo-ui.css', $html);
            self::assertStringContainsString('data-host-js', $html);
        }
    }

    #[Test]
    public function aCanonicalLayoutIsUsedAsIs(): void
    {
        $twig  = $this->twig();
        $pages = new WebUiPages(WebUiPages::OVERRIDES, 'canonical.html.twig', ['edit' => 'host/edit.html.twig'], ['form/host.html.twig'], $twig);

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertNull($pages->legacyLayout());
        self::assertSame('host/edit.html.twig', $pages->template('edit'));
        self::assertSame(WebUiPages::TEMPLATES[WebUiPages::OVERRIDES]['index'], $pages->template('index'));
        self::assertSame(['form/host.html.twig'], $pages->formThemes());
        self::assertSame(['a' => 1, WebUiPages::LEGACY_LAYOUT_VARIABLE => null], $pages->context(['a' => 1]));

        $html = $twig->render(WebUiPages::TEMPLATES[WebUiPages::OVERRIDES]['index'], WebUiPageFixtures::overridesIndex(pages: $pages));
        self::assertStringContainsString('<div data-host-canonical><title>Site texts</title>', $html);
    }

    #[Test]
    public function formerDefaultsUnknownLayoutsAndMissingTwigFallBack(): void
    {
        foreach (WebUiPages::FORMER_DEFAULT_LAYOUTS as $ui => $former) {
            self::assertNull((new WebUiPages($ui, $former, [], [], $this->twig()))->layout(), $ui);
            self::assertNull((new WebUiPages($ui, ''))->layout(), $ui);
        }

        $missing = new WebUiPages(WebUiPages::MISSING_LOG, 'missing/layout.html.twig', [], [], $this->twig());
        self::assertSame('missing/layout.html.twig', $missing->layout());

        $noTwig = new WebUiPages(WebUiPages::OVERRIDES, 'legacy/base.html.twig');
        self::assertSame('legacy/base.html.twig', $noTwig->layout());

        $default = new WebUiPages();
        self::assertNull($default->layout());
        self::assertSame([WebUiPages::DEFAULT_FORM_THEME], $default->formThemes());
        self::assertSame(WebUiPages::TEMPLATES[WebUiPages::MISSING_LOG]['index'], $default->template('index'));
        self::assertSame('unknown_page', $default->template('unknown_page'));
        self::assertSame(['key' => 'k', 'domain' => WebUiPages::DOMAIN, 'params' => ['%n%' => 1]], (array) WebUiPages::label('k', ['%n%' => 1]));

        $this->expectException(InvalidArgumentException::class);
        new WebUiPages('dashboard');
    }

    /**
     * Runs $callback and collects the E_USER_DEPRECATED messages it triggers.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return array{result: T, messages: list<string>}
     */
    private function deprecations(callable $callback): array
    {
        $messages = [];
        // @phpstan-ignore frankenphp.worker.noSetErrorExceptionHandler (test only: restored in finally, never in a worker)
        set_error_handler(static function (int $level, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $result = $callback();
        } finally {
            restore_error_handler();
        }

        return ['result' => $result, 'messages' => $messages];
    }

    private function twig(): Environment
    {
        $case = new class('legacy') extends PageContractTestCase {
            public static function pageFixtures(): iterable
            {
                return [];
            }

            public function environment(): Environment
            {
                return $this->createTwig();
            }

            protected static function templatePaths(): array
            {
                return ['NowoTranslationYamlToolsBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
            }

            protected static function translationDirs(): array
            {
                return [dirname(__DIR__, 3) . '/src/Resources/translations'];
            }

            protected static function formThemes(): array
            {
                return [WebUiPages::DEFAULT_FORM_THEME];
            }

            protected static function inlineTemplates(): array
            {
                return LegacyLayoutBridgeTest::LAYOUTS;
            }
        };

        return $case->environment();
    }
}
