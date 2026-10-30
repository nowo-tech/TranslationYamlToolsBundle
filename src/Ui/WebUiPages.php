<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Ui;

use InvalidArgumentException;
use Nowo\UiKitBundle\View\Label;
use Throwable;
use Twig\Environment;

use function array_key_exists;
use function in_array;
use function is_string;
use function sprintf;
use function trigger_deprecation;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003) for one of the two opt-in Web UIs of the bundle: the
 * missing-translation log ({@see self::MISSING_LOG}, `missing_translation_log.web_ui`) and the text-overrides desk
 * ({@see self::OVERRIDES}, `overrides.web_ui`).
 *
 * Resolves the layout the page scaffolds extend (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; the
 * former bundle default layouts mean null), wraps a 1.x layout (no UiKit canonical blocks) in a deprecated bridge,
 * resolves the per-page template overrides (`web_ui.templates.<page>`) and the form themes of the form pages
 * (`web_ui.form_themes`). Configuration only: no request state, safe in worker mode.
 */
final readonly class WebUiPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoTranslationYamlToolsBundle`). */
    public const BUNDLE = 'NowoTranslationYamlToolsBundle';

    /** Translation domain of the bundle labels. */
    public const DOMAIN = 'NowoTranslationYamlToolsBundle';

    /** Missing-translation log Web UI (`missing_translation_log.web_ui`). */
    public const MISSING_LOG = 'missing_log';

    /** Text-overrides desk (`overrides.web_ui`). */
    public const OVERRIDES = 'overrides';

    /** Page ids accepted by `web_ui.templates`, per Web UI (page id => default template). */
    public const TEMPLATES = [
        self::MISSING_LOG => [
            'index' => '@NowoTranslationYamlToolsBundle/missing_translation_log/index.html.twig',
        ],
        self::OVERRIDES => [
            'index' => '@NowoTranslationYamlToolsBundle/text_override/index.html.twig',
            'edit'  => '@NowoTranslationYamlToolsBundle/text_override/edit.html.twig',
        ],
    ];

    /** Deprecated BC bridges around a 1.x layout (removed in 2.0), per Web UI. */
    public const LEGACY_LAYOUT_BRIDGES = [
        self::MISSING_LOG => '@NowoTranslationYamlToolsBundle/missing_translation_log/_legacy_layout.html.twig',
        self::OVERRIDES   => '@NowoTranslationYamlToolsBundle/text_override/_legacy_layout.html.twig',
    ];

    /** Former bundle default layouts (removed in 1.14; configured explicitly they now mean "UiKit layout"). */
    public const FORMER_DEFAULT_LAYOUTS = [
        self::MISSING_LOG => '@NowoTranslationYamlToolsBundle/missing_translation_log/layout.html.twig',
        self::OVERRIDES   => '@NowoTranslationYamlToolsBundle/text_override/layout.html.twig',
    ];

    /** Default form theme of the form pages (UiKit form layout: `nowo-ui-*` classes and theme slots only). */
    public const DEFAULT_FORM_THEME = '@NowoUiKit/form/nowo_ui_layout.html.twig';

    /** Variable of the legacy bridges: the wrapped 1.x layout. */
    public const LEGACY_LAYOUT_VARIABLE = 'nowo_translation_yaml_tools_legacy_layout';

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_content';

    /**
     * @param string $ui {@see self::MISSING_LOG} or {@see self::OVERRIDES}
     * @param array<string,string> $templates `web_ui.templates` (page id => template)
     * @param list<string> $formThemes `web_ui.form_themes`
     * @param Environment|null $twig detects a 1.x layout (null: the configured layout is used as is)
     */
    public function __construct(
        private string $ui = self::MISSING_LOG,
        private ?string $layoutTemplate = null,
        private array $templates = [],
        private array $formThemes = [self::DEFAULT_FORM_THEME],
        private ?Environment $twig = null,
    ) {
        if (!array_key_exists($ui, self::TEMPLATES)) {
            throw new InvalidArgumentException(sprintf('Unknown Web UI "%s" (%s|%s).', $ui, self::MISSING_LOG, self::OVERRIDES));
        }
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        $override = $this->templates[$page] ?? null;

        return is_string($override) && $override !== '' ? $override : self::TEMPLATES[$this->ui][$page] ?? $page;
    }

    /**
     * Form themes of the form pages (`FormPage::$formThemes`).
     *
     * @return list<string>
     */
    public function formThemes(): array
    {
        return $this->formThemes;
    }

    /**
     * Layout the page scaffolds extend (`page.layout`): the configured layout, the legacy bridge for a 1.x layout,
     * else null (UiKit `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        return $this->legacyLayout() !== null ? self::LEGACY_LAYOUT_BRIDGES[$this->ui] : $this->configuredLayout();
    }

    /**
     * Page variables plus the shared ones: the 1.x layout wrapped by the deprecated bridge (null when none).
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $vars + [self::LEGACY_LAYOUT_VARIABLE => $this->legacyLayout()];
    }

    /**
     * The configured layout when it is a 1.x layout (it does not define the UiKit canonical blocks), else null.
     */
    public function legacyLayout(): ?string
    {
        $layout = $this->configuredLayout();
        if ($layout === null || !$this->twig instanceof Environment) {
            return null;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->getGlobals());
        } catch (Throwable) {
            return null;
        }

        if ($canonical) {
            return null;
        }

        trigger_deprecation('nowo-tech/translation-yaml-tools-bundle', '1.14', 'The layout "%s" does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...); it is wrapped in the deprecated bridge "%s". Render the canonical blocks (or extend @NowoUiKit/layout/admin.html.twig) or set web_ui.layout_template to null to use nowo_ui_kit.layouts.admin; see docs/UPGRADING.md.', $layout, self::LEGACY_LAYOUT_BRIDGES[$this->ui]);

        return $layout;
    }

    private function configuredLayout(): ?string
    {
        $layout = $this->layoutTemplate;
        if (in_array($layout, [null, '', self::FORMER_DEFAULT_LAYOUTS[$this->ui]], true)) {
            return null;
        }

        return $layout;
    }
}
