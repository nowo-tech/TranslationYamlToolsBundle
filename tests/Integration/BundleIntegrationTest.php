<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration;

use Nowo\TranslationYamlToolsBundle\Tests\Kernel\TestKernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Verifies the bundle boots and console commands are registered.
 */
#[CoversNothing]
final class BundleIntegrationTest extends KernelTestCase
{
    /**
     * {@inheritdoc}
     */
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testKernelBoots(): void
    {
        self::bootKernel();
        self::assertTrue(self::getContainer()->has('kernel'));
    }

    public function testTranslationYamlCommandsAreRegistered(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        self::assertTrue($application->has('nowo:translation-yaml:tree'));
        self::assertTrue($application->has('nowo:translation-yaml:sort'));
        self::assertTrue($application->has('nowo:translation-yaml:fill-missing'));
        self::assertTrue($application->has('nowo:translation-yaml:audit'));
    }

    public function testTreeCommandDryRunAgainstFixtureTranslations(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);
        $command     = $application->find('nowo:translation-yaml:tree');
        $tester      = new CommandTester($command);
        $exit        = $tester->execute([
            '--domain'  => 'messages',
            '--locale'  => 'en',
            '--dry-run' => true,
        ]);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Dry-run', $tester->getDisplay());
    }
}
