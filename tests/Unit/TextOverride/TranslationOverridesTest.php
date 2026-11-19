<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use Doctrine\DBAL\Driver\AbstractException as DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TranslationYamlToolsBundle\TextOverride\Entity\TranslationOverride;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\StripTagsTranslationOverrideHtmlSanitizer;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\SymfonyTranslationOverrideHtmlSanitizer;
use Nowo\TranslationYamlToolsBundle\TextOverride\Html\TranslationOverrideHtmlSanitizerInterface;
use Nowo\TranslationYamlToolsBundle\TextOverride\Repository\TranslationOverrideRepository;
use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverrides;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

#[CoversClass(TranslationOverrides::class)]
final class TranslationOverridesTest extends TestCase
{
    public function testLoadsMapOncePerRequestThroughCache(): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->expects(self::once())->method('loadMap')->willReturn(['es' => ['messages' => ['site.a' => 'A']]]);
        $overrides = new TranslationOverrides($repository, new ArrayAdapter(), new SymfonyTranslationOverrideHtmlSanitizer());

        self::assertSame('A', $overrides->get('es', 'messages', 'site.a'));
        self::assertNull($overrides->get('en', 'messages', 'site.a'));
        $overrides->reset();
        self::assertSame('A', $overrides->get('es', 'messages', 'site.a'), 'Second load comes from the cache pool.');
    }

    public function testResetDropsTheMemoSoAnotherWorkersEditIsSeen(): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->expects(self::exactly(2))->method('loadMap')->willReturnOnConsecutiveCalls(
            ['en' => ['messages' => ['site.a' => 'Before']]],
            ['en' => ['messages' => ['site.a' => 'After']]],
        );
        $cache     = new ArrayAdapter();
        $overrides = new TranslationOverrides($repository, $cache, new SymfonyTranslationOverrideHtmlSanitizer());

        self::assertSame('Before', $overrides->get('en', 'messages', 'site.a'));
        // Another worker saves and drops the shared cache entry; this instance still serves its memo.
        $cache->delete(TranslationOverrides::CACHE_KEY);
        self::assertSame('Before', $overrides->get('en', 'messages', 'site.a'));

        $overrides->reset();
        self::assertSame('After', $overrides->get('en', 'messages', 'site.a'));
    }

    public function testStorageFailureMeansNoOverrides(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('get')->willThrowException(new RuntimeException('no schema'));
        $cache->method('delete')->willThrowException(new RuntimeException('cache down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning');
        $overrides = new TranslationOverrides($this->createStub(TranslationOverrideRepository::class), $cache, new StripTagsTranslationOverrideHtmlSanitizer(), $logger);

        self::assertSame([], $overrides->all());
        $overrides->invalidate();
    }

    public function testSetCreatesUpdatesAndRemovesRows(): void
    {
        $existing   = (new TranslationOverride('es', 'messages', 'site.b'))->setValue('old');
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->expects(self::once())->method('persist')->with(self::callback(
            static fn (TranslationOverride $row): bool => $row->getLocale() === 'en' && $row->getValue() === 'Hi' && $row->getMessageKey() === 'site.a' && $row->getDomain() === 'messages' && $row->getUpdatedBy() === 'ana',
        ));
        $repository->expects(self::once())->method('delete')->with($existing);
        // 1.19: a set() with flush is one write unit (staging + flush inside transactional()).
        $repository->expects(self::exactly(4))->method('transactional')->willReturnCallback(static fn (callable $work): mixed => $work());
        $repository->expects(self::never())->method('flush');
        $repository->method('findOne')->willReturnCallback(
            static fn (string $locale, string $domain, string $key): ?TranslationOverride => $key === 'site.b' ? $existing : null,
        );
        $cache = new ArrayAdapter();
        $cache->get(TranslationOverrides::CACHE_KEY, static fn (): array => ['stale' => []]);
        $overrides = new TranslationOverrides($repository, $cache, new SymfonyTranslationOverrideHtmlSanitizer());

        $overrides->set('en', 'messages', 'site.a', '  Hi  ', true, 'ana');
        self::assertFalse($cache->hasItem(TranslationOverrides::CACHE_KEY), 'Writes drop the cached map.');
        $overrides->set('es', 'messages', 'site.b', 'new', true, 'bob');
        self::assertSame('new', $existing->getValue());
        self::assertSame('bob', $existing->getUpdatedBy());
        $overrides->set('es', 'messages', 'site.b', 'new'); // unchanged
        $overrides->set('es', 'messages', 'site.b', '   ');
        $overrides->set('fr', 'messages', 'site.c', null, false); // nothing to remove, no flush
        self::assertNull($existing->getId());
    }

    public function testFlushWritesThroughTheRepositoryAndDropsTheCache(): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->expects(self::once())->method('flush');
        $cache = new ArrayAdapter();
        $cache->get(TranslationOverrides::CACHE_KEY, static fn (): array => ['stale' => []]);

        (new TranslationOverrides($repository, $cache, new SymfonyTranslationOverrideHtmlSanitizer()))->flush();

        self::assertFalse($cache->hasItem(TranslationOverrides::CACHE_KEY));
    }

    public function testWriteRunsTheStageAgainAfterAUniqueViolation(): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->method('isCleanBundleManager')->willReturn(true);
        $calls = 0;
        $repository->expects(self::exactly(2))->method('transactional')->willReturnCallback(static function (callable $work) use (&$calls): mixed {
            if (++$calls === 1) {
                throw new RuntimeException('flush failed', 0, self::uniqueViolation());
            }

            return $work();
        });
        $staged = 0;
        $cache  = new ArrayAdapter();
        $cache->get(TranslationOverrides::CACHE_KEY, static fn (): array => ['stale' => []]);

        (new TranslationOverrides($repository, $cache, new SymfonyTranslationOverrideHtmlSanitizer()))->write(static function () use (&$staged): void {
            ++$staged;
        });

        self::assertSame(1, $staged, 'The second run (the winner\'s row now exists) stages again.');
        self::assertFalse($cache->hasItem(TranslationOverrides::CACHE_KEY));
    }

    public function testWriteRethrowsAnyOtherFailureAndASecondUniqueViolation(): void
    {
        $other      = new RuntimeException('deadlock');
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->expects(self::once())->method('transactional')->willThrowException($other);
        $overrides = new TranslationOverrides($repository, new ArrayAdapter(), new SymfonyTranslationOverrideHtmlSanitizer());
        try {
            $overrides->write(static function (): void {
            });
            self::fail('Expected the failure.');
        } catch (RuntimeException $caught) {
            self::assertSame($other, $caught);
        }

        $unique     = self::uniqueViolation();
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->method('isCleanBundleManager')->willReturn(true);
        $repository->expects(self::exactly(2))->method('transactional')->willThrowException($unique);
        $this->expectExceptionObject($unique);
        (new TranslationOverrides($repository, new ArrayAdapter(), new SymfonyTranslationOverrideHtmlSanitizer()))->write(static function (): void {
        });
    }

    public function testWriteNeverRunsAgainWhenWorkWasPendingBeforeTheUnit(): void
    {
        // Audit UQ-1: rows staged with "flush: false" (or the default manager's own entities) were discarded by the
        // failed unit; a second run would report success without them.
        $unique     = self::uniqueViolation();
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->method('isCleanBundleManager')->willReturn(false);
        $repository->expects(self::once())->method('transactional')->willThrowException($unique);
        $cache = new ArrayAdapter();
        $cache->get(TranslationOverrides::CACHE_KEY, static fn (): array => ['stale' => []]);

        try {
            (new TranslationOverrides($repository, $cache, new SymfonyTranslationOverrideHtmlSanitizer()))->write(static function (): void {
            });
            self::fail('The unique violation must be rethrown.');
        } catch (UniqueConstraintViolationException $caught) {
            self::assertSame($unique, $caught);
        }
        self::assertTrue($cache->hasItem(TranslationOverrides::CACHE_KEY), 'Nothing was written: the cache is kept.');
    }

    private static function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(new class('duplicate') extends DriverException {
        }, null);
    }

    public function testMarkupIsSanitizedOnSave(): void
    {
        $repository = $this->createMock(TranslationOverrideRepository::class);
        $repository->method('findOne')->willReturn(null);
        $repository->expects(self::once())->method('persist')->with(self::callback(
            static fn (TranslationOverride $row): bool => $row->getValue() === 'Acepto la <a href="/privacidad" rel="noopener noreferrer">política</a>',
        ));
        $overrides = new TranslationOverrides($repository, new ArrayAdapter(), new SymfonyTranslationOverrideHtmlSanitizer());

        $overrides->set('es', 'messages', 'site.consent', 'Acepto la <a href="/privacidad" onclick="steal()">política</a> <script>alert(1)</script><img src=x onerror=alert(2)>', false);
    }

    public function testRowsWrittenOutsideSetAreSanitizedWhenLoaded(): void
    {
        $repository = $this->createStub(TranslationOverrideRepository::class);
        $repository->method('loadMap')->willReturn(['es' => ['messages' => [
            'site.plain'    => 'Sin marcas & cosas',
            'site.restored' => 'Hola <a href="javascript:alert(1)">x</a><script>alert(2)</script>',
        ]]]);
        $sanitizer = $this->createMock(TranslationOverrideHtmlSanitizerInterface::class);
        $sanitizer->expects(self::once())->method('sanitize')->willReturnCallback(
            static fn (string $html): string => (new SymfonyTranslationOverrideHtmlSanitizer())->sanitize($html),
        );

        $overrides = new TranslationOverrides($repository, new ArrayAdapter(), $sanitizer);
        $text      = $overrides->get('es', 'messages', 'site.restored');

        self::assertNotNull($text);
        self::assertStringNotContainsString('script', $text);
        self::assertStringNotContainsString('javascript:', $text);
        self::assertSame('Sin marcas & cosas', $overrides->get('es', 'messages', 'site.plain'), 'Plain text is left untouched.');
    }

    public function testCleanTrimsAndSanitizes(): void
    {
        $overrides = new TranslationOverrides($this->createStub(TranslationOverrideRepository::class), new ArrayAdapter(), new StripTagsTranslationOverrideHtmlSanitizer());

        self::assertSame('', $overrides->clean(null));
        self::assertSame('a & b', $overrides->clean('  a & b '));
        self::assertSame('bold', $overrides->clean(' <b>bold</b> '));
    }
}
