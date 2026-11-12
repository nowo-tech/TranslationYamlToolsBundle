<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\DependencyInjection;

use Nowo\TranslationYamlToolsBundle\DependencyInjection\NowoTranslationYamlToolsExtension;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerFlushBridge;
use Nowo\TranslationYamlToolsBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsEntityManager;
use Nowo\TranslationYamlToolsBundle\Doctrine\TranslationYamlToolsManagerRegistry;
use Nowo\TranslationYamlToolsBundle\Entity\MissingTranslationLog;
use Nowo\TranslationYamlToolsBundle\EventSubscriber\EntityManagerBoundarySubscriber;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.18.0 (REQ-ARCH-001 rule 6): which entity manager the bundle writes through, per configuration.
 */
#[CoversClass(NowoTranslationYamlToolsExtension::class)]
final class EntityManagerModesExtensionTest extends TestCase
{
    public function testNoDoctrineFeatureRegistersNothing(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->hasDefinition(EntityManagerBoundarySubscriber::class));
        self::assertFalse($container->hasDefinition(TranslationYamlToolsEntityManager::class));
    }

    public function testZeroConfigWritesThroughThePrivateManager(): void
    {
        $container = $this->load(['overrides' => ['enabled' => true], 'missing_translation_log' => ['enabled' => true]]);

        $private = $container->getDefinition(TranslationYamlToolsEntityManager::class);
        self::assertSame([MissingTranslationLog::class, TranslationOverride::class], $private->getArgument(1));
        self::assertSame([['method' => 'reset']], $private->getTag('kernel.reset'));
        foreach ([MissingTranslationLogRepository::class, TranslationOverrideRepository::class] as $repository) {
            self::assertSame(TranslationYamlToolsManagerRegistry::class, (string) $container->getDefinition($repository)->getArgument('$registry'));
        }
        self::assertTrue($container->getDefinition(PrivateEntityManagerMiddleware::class)->hasTag('doctrine.middleware'));
        self::assertSame([['event' => 'postFlush']], $container->getDefinition(PrivateEntityManagerFlushBridge::class)->getTag('doctrine.event_listener'));

        $resettables = $container->getDefinition(EntityManagerBoundarySubscriber::class)->getArgument('$resettables');
        self::assertInstanceOf(IteratorArgument::class, $resettables);
        $reference = $resettables->getValues()[0];
        self::assertInstanceOf(Reference::class, $reference);
        self::assertSame(TranslationYamlToolsEntityManager::class, (string) $reference);
        self::assertSame(ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE, $reference->getInvalidBehavior());
        self::assertTrue($container->getDefinition(EntityManagerBoundarySubscriber::class)->hasTag('kernel.event_subscriber'));
    }

    public function testOnlyTheEnabledFeatureEntitiesNameTheSource(): void
    {
        $container = $this->load(['overrides' => ['enabled' => true]]);

        self::assertSame([TranslationOverride::class], $container->getDefinition(TranslationYamlToolsEntityManager::class)->getArgument(1));
        self::assertFalse($container->hasDefinition(MissingTranslationLogRepository::class));
    }

    #[IgnoreDeprecations]
    public function testDeprecatedOptOutKeepsTheApplicationManager(): void
    {
        $container = $this->load(['overrides' => ['enabled' => true], 'doctrine' => ['private_entity_manager' => false]]);

        self::assertFalse($container->hasDefinition(TranslationYamlToolsEntityManager::class));
        self::assertSame('doctrine', (string) $container->getDefinition(TranslationOverrideRepository::class)->getArgument('$registry'));
        self::assertTrue($container->hasDefinition(EntityManagerBoundarySubscriber::class));
    }

    public function testDedicatedManagerSkipsThePrivateManager(): void
    {
        $container = $this->load(['missing_translation_log' => ['enabled' => true], 'doctrine' => ['entity_manager' => 'translations']]);

        self::assertFalse($container->hasDefinition(TranslationYamlToolsEntityManager::class));
        self::assertSame('translations', $container->getDefinition(EntityManagerBoundarySubscriber::class)->getArgument('$managerName'));
        self::assertSame('doctrine', (string) $container->getDefinition(MissingTranslationLogRepository::class)->getArgument('$registry'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.enabled_locales', []);
        (new NowoTranslationYamlToolsExtension())->load([$config], $container);

        return $container;
    }
}
