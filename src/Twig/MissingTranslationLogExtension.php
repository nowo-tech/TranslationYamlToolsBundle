<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Deprecated 1.x Twig globals of the missing-translation log Web UI (removed in 2.0). Since 1.14 the pages are UiKit
 * page scaffolds (REQ-UI-003) and read neither global; they stay for host templates written for 1.x.
 *
 * Global {@see self::GLOBAL_LAYOUT_TEMPLATE}: {@code missing_translation_log.web_ui.layout_template} (null = the
 * UiKit layout {@code nowo_ui_layout.admin}).
 *
 * Global {@see self::GLOBAL_CSS_FRAMEWORK}: the deprecated {@code missing_translation_log.web_ui.css_framework}
 * (prefer {@code nowo_ui_kit_css_framework}).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 */
final class MissingTranslationLogExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_LAYOUT_TEMPLATE = 'nowo_translation_yaml_tools_missing_log_layout_template';

    public const GLOBAL_CSS_FRAMEWORK = 'nowo_translation_yaml_tools_css_framework';

    public function __construct(
        private readonly ?string $layoutTemplate = null,
        private readonly string $cssFramework = 'bootstrap5',
    ) {
    }

    public function getGlobals(): array
    {
        return [
            self::GLOBAL_LAYOUT_TEMPLATE => $this->layoutTemplate,
            self::GLOBAL_CSS_FRAMEWORK   => $this->cssFramework,
        ];
    }
}
