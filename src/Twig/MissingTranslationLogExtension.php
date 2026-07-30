<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig globals for the missing-translation log Web UI.
 *
 * Global {@see self::GLOBAL_LAYOUT_TEMPLATE}: layout extended by
 * {@code missing_translation_log/base.html.twig} (from
 * {@code nowo_translation_yaml_tools.missing_translation_log.web_ui.layout_template}).
 *
 * Global {@see self::GLOBAL_CSS_FRAMEWORK}: host CSS stack hint
 * ({@code missing_translation_log.web_ui.css_framework}).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 */
final class MissingTranslationLogExtension extends AbstractExtension implements GlobalsInterface
{
    public const GLOBAL_LAYOUT_TEMPLATE = 'nowo_translation_yaml_tools_missing_log_layout_template';

    public const GLOBAL_CSS_FRAMEWORK = 'nowo_translation_yaml_tools_css_framework';

    public function __construct(
        private readonly string $layoutTemplate,
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
