<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle;

use LogicException;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\MissingLogWebUiSecurityPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Developer tools for Symfony YAML translations: tree and flat layouts, sorting, and machine fill for missing keys.
 */
final class NowoTranslationYamlToolsBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new MissingLogWebUiSecurityPass());
    }

    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new NowoTranslationYamlToolsExtension();
        }

        // Typed Bundle::$extension cannot hold a non-ExtensionInterface value on PHP 8.2+.
        // @codeCoverageIgnoreStart
        if (!$this->extension instanceof ExtensionInterface) {
            throw new LogicException('Bundle extension must implement ExtensionInterface.');
        }
        // @codeCoverageIgnoreEnd

        return $this->extension;
    }
}
