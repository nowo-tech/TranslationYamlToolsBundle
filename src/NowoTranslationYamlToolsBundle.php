<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Developer tools for Symfony YAML translations: tree and flat layouts, sorting, and machine fill for missing keys.
 */
final class NowoTranslationYamlToolsBundle extends Bundle
{
    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new NowoTranslationYamlToolsExtension();
        }

        return $this->extension;
    }
}
