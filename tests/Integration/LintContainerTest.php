<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Integration;

use Closure;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\DddHostTestKernel;
use Nowo\TranslationYamlToolsBundle\Tests\Kernel\PrivateEntityManagerTestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * `lint:container` (Symfony's alias validity and type declaration checks over the compiled container) passes for every
 * 1.18.0 entity manager mode: the private manager (zero config), the deprecated opt-out, a dedicated manager in a
 * standard host and a dedicated manager in a DDD host.
 */
final class LintContainerTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): KernelInterface}>
     */
    public static function kernels(): iterable
    {
        yield 'private entity manager (zero config)' => [static fn (): KernelInterface => new PrivateEntityManagerTestKernel('test', true, 'private')];
        yield 'private_entity_manager: false' => [static fn (): KernelInterface => new PrivateEntityManagerTestKernel('test', true, 'legacy')];
        yield 'dedicated entity manager' => [static fn (): KernelInterface => new PrivateEntityManagerTestKernel('test', true, 'dedicated')];
        yield 'DDD host' => [static fn (): KernelInterface => new DddHostTestKernel('test', true)];
    }

    /**
     * @param Closure(): KernelInterface $kernel
     */
    #[DataProvider('kernels')]
    #[IgnoreDeprecations]
    public function testLintContainerPasses(Closure $kernel): void
    {
        $instance = $kernel();
        $instance->boot();
        $application = new Application($instance);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));

        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('linted successfully', $tester->getDisplay());
    }
}
