<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\Compiler\DebugOnlyRequirement;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

#[CoversClass(DebugOnlyRequirement::class)]
final class DebugOnlyRequirementTest extends TestCase
{
    public function testPassesInDebug(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', true);

        DebugOnlyRequirement::assert($container, 'overrides.web_ui.security.allow_unauthenticated');
        $this->addToAssertionCount(1);
    }

    public function testFailsWithoutDebugOrWhenUnknown(): void
    {
        foreach ([['kernel.debug' => false], ['kernel.debug' => '1'], []] as $parameters) {
            $container = new ContainerBuilder();
            foreach ($parameters as $name => $value) {
                $container->setParameter($name, $value);
            }

            try {
                DebugOnlyRequirement::assert($container, 'overrides.web_ui.security.allow_unauthenticated');
                self::fail('Expected an InvalidConfigurationException.');
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString('only allowed when kernel.debug is true', $e->getMessage());
            }
        }
    }
}
