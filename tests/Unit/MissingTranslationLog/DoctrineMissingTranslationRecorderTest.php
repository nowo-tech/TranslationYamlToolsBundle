<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\DoctrineMissingTranslationRecorder;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferEvent;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferMessage;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;

#[CoversClass(DoctrineMissingTranslationRecorder::class)]
final class DoctrineMissingTranslationRecorderTest extends TestCase
{
    public function testFlushDispatchesMessageWhenAsyncPersistAndBusPresent(): void
    {
        if (!interface_exists(MessageBusInterface::class)) {
            self::markTestSkipped('symfony/messenger is not installed');
        }

        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::never())->method('persistBuffer');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->willReturnCallback(static function (object $message): Envelope {
            self::assertInstanceOf(MissingTranslationBufferMessage::class, $message);

            return new Envelope($message);
        });

        $recorder = new DoctrineMissingTranslationRecorder($repository, $bus, true, 'messenger');
        $recorder->record('key.one', 'messages', 'en');
        $recorder->flushBuffer();
    }

    public function testFlushPersistsSynchronouslyWhenAsyncPersistFalse(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::once())->method('persistBuffer')->with(self::callback(static function (array $buffer): bool {
            return count($buffer) === 1;
        }));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $recorder = new DoctrineMissingTranslationRecorder($repository, $bus, false, 'messenger');
        $recorder->record('key.one', 'messages', 'en');
        $recorder->flushBuffer();
    }

    public function testFlushFallsBackToRepositoryWhenAsyncPersistTrueButNoBus(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::once())->method('persistBuffer');

        $recorder = new DoctrineMissingTranslationRecorder($repository, null, true, 'messenger');
        $recorder->record('key.one', 'messages', 'en');
        $recorder->flushBuffer();
    }

    public function testFlushDispatchesEventWhenAsyncPersistStrategyEventDispatcher(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::never())->method('persistBuffer');

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->with(self::isInstanceOf(MissingTranslationBufferEvent::class));

        $recorder = new DoctrineMissingTranslationRecorder($repository, null, true, 'event_dispatcher', $dispatcher);
        $recorder->record('key.one', 'messages', 'en');
        $recorder->flushBuffer();
    }

    public function testFlushFallsBackToRepositoryWhenStrategyEventDispatcherButNoDispatcher(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::once())->method('persistBuffer');

        $recorder = new DoctrineMissingTranslationRecorder($repository, null, true, 'event_dispatcher');
        $recorder->record('key.one', 'messages', 'en');
        $recorder->flushBuffer();
    }

    public function testRecordIgnoresEmptyLocale(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::never())->method('persistBuffer');

        $recorder = new DoctrineMissingTranslationRecorder($repository);
        $recorder->record('k', 'messages', '');
        $recorder->flushBuffer();
    }

    public function testResetClearsBuffer(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::never())->method('persistBuffer');

        $recorder = new DoctrineMissingTranslationRecorder($repository);
        $recorder->record('k', 'messages', 'en');
        $recorder->reset();
        $recorder->flushBuffer();
    }

    public function testFlushBufferNoOpWhenBufferWasEmpty(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::never())->method('persistBuffer');

        $recorder = new DoctrineMissingTranslationRecorder($repository);
        $recorder->flushBuffer();
    }

    public function testRecordStoresCallSiteWhenNonEmpty(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::once())->method('persistBuffer')->with(self::callback(static function (array $buffer): bool {
            foreach ($buffer as $row) {
                if (($row['callSite'] ?? null) === '/src/Foo.php:10') {
                    return true;
                }
            }

            return false;
        }));

        $recorder = new DoctrineMissingTranslationRecorder($repository);
        $recorder->record('k', 'messages', 'en', '/src/Foo.php:10');
        $recorder->flushBuffer();
    }
}
