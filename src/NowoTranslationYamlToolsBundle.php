<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle;

use LogicException;
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
    }

    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if ($this->extension === null) {
            $this->extension = new NowoTranslationYamlToolsExtension();
        }

        if (!$this->extension instanceof ExtensionInterface) {
            throw new LogicException('Bundle extension must implement ExtensionInterface.');
        }

        return $this->extension;
    }
}
