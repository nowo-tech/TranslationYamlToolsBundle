<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\EventListener\TranslationYamlToolsConflictListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * 1.19 (REQ-RUNTIME-003 rule 6): the conflict listener exists only with a Web UI, with the `conflict_response` policy.
 */
#[CoversClass(NowoTranslationYamlToolsExtension::class)]
final class ConflictResponseExtensionTest extends TestCase
{
    private const POLICY = 'nowo_translation_yaml_tools.conflict_response.policy';

    public function testNoWebUiNoListener(): void
    {
        $container = $this->load(['overrides' => ['enabled' => true, 'web_ui' => ['enabled' => false]], 'missing_translation_log' => ['enabled' => true]]);

        self::assertFalse($container->hasDefinition(TranslationYamlToolsConflictListener::class));
        self::assertFalse($container->hasDefinition(self::POLICY));
    }

    public function testOverridesDeskGetsThe409DefaultPolicy(): void
    {
        $container = $this->load(['overrides' => ['enabled' => true, 'web_ui' => ['enabled' => true]]]);

        self::assertSame([409, 1], $container->getDefinition(self::POLICY)->getArguments());
        $listener = $container->getDefinition(TranslationYamlToolsConflictListener::class);
        self::assertSame([['event' => 'kernel.exception', 'priority' => -64]], $listener->getTag('kernel.event_listener'));
        self::assertSame(self::POLICY, (string) $listener->getArgument(0));
    }

    public function testMissingLogPagesGetTheConfigured503Policy(): void
    {
        $container = $this->load([
            'missing_translation_log' => ['enabled' => true, 'web_ui' => ['enabled' => true]],
            'conflict_response'       => ['status' => 503, 'retry_after' => 5],
        ]);

        self::assertSame([503, 5], $container->getDefinition(self::POLICY)->getArguments());
        self::assertTrue($container->hasDefinition(TranslationYamlToolsConflictListener::class));
    }

    public function testOnly409Or503(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->load(['conflict_response' => ['status' => 500]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoTranslationYamlToolsExtension())->load([$config], $container);

        return $container;
    }
}
