<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle;

use LogicException;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\EntityManagerOptionPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\MissingLogWebUiSecurityPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\TextOverrideWebUiPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
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
        $container->addCompilerPass(new TextOverrideWebUiPass());
        // 1.17.0 (audit LD-TYT-L1): the entities on doctrine.entity_manager only, never also on an auto-mapped manager.
        $container->addCompilerPass(new EntityManagerOptionPass());
        // 1.18.0 (rule 6): the private entity manager's rollback middleware only on its own connection, before
        // DoctrineBundle's middleware pass.
        $container->addCompilerPass(new PrivateEntityManagerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
    }

    /**
     * {@inheritdoc}
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
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
