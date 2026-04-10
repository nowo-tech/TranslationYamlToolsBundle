<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferDoctrinePersistListener;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferEvent;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissingTranslationBufferDoctrinePersistListener::class)]
final class MissingTranslationBufferDoctrinePersistListenerTest extends TestCase
{
    public function testPersistsWhenPropagationNotStopped(): void
    {
        $buffer = [
            'h' => [
                'hits'      => 1,
                'messageId' => 'key',
                'domain'    => 'messages',
                'locale'    => 'en',
                'callSite'  => null,
            ],
        ];

        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::once())->method('persistBuffer')->with($buffer);

        $listener = new MissingTranslationBufferDoctrinePersistListener($repository);
        ($listener)(new MissingTranslationBufferEvent($buffer));
    }

    public function testSkipsPersistWhenPropagationStopped(): void
    {
        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::never())->method('persistBuffer');

        $event = new MissingTranslationBufferEvent([]);
        $event->stopPropagation();

        $listener = new MissingTranslationBufferDoctrinePersistListener($repository);
        ($listener)($event);
    }
}
