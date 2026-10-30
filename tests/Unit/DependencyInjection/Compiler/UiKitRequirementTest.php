<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\UiKitRequirement;
use Nowo\UiKitBundle\NowoUiKitBundle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(UiKitRequirement::class)]
final class UiKitRequirementTest extends TestCase
{
    public function testPassesWhenUiKitIsRegistered(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', ['NowoUiKitBundle' => NowoUiKitBundle::class]);

        UiKitRequirement::assert($container, 'overrides.web_ui.enabled');
        $this->addToAssertionCount(1);
    }

    public function testFailsWithoutUiKitOrWithoutTheBundlesParameter(): void
    {
        foreach ([['kernel.bundles' => ['TwigBundle' => 'X']], []] as $parameters) {
            $container = new ContainerBuilder();
            foreach ($parameters as $name => $value) {
                $container->setParameter($name, $value);
            }

            try {
                UiKitRequirement::assert($container, 'missing_translation_log.web_ui.enabled');
                self::fail('Expected an InvalidConfigurationException.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('missing_translation_log.web_ui.enabled requires NowoUiKitBundle', $e->getMessage());
            }
        }
    }
}
