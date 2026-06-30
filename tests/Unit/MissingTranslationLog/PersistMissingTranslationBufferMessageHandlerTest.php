<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferMessage;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\PersistMissingTranslationBufferMessageHandler;
use Nowo\TranslationYamlToolsBundle\Repository\MissingTranslationLogRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersistMissingTranslationBufferMessageHandler::class)]
final class PersistMissingTranslationBufferMessageHandlerTest extends TestCase
{
    public function testInvokeDelegatesToRepository(): void
    {
        $buffer = ['h' => [
            'hits'          => 1,
            'messageId'     => 'm',
            'domain'        => 'd',
            'locale'        => 'en',
            'callSite'      => null,
            'requestRoute'  => null,
            'requestMethod' => null,
            'requestPath'   => null,
        ]];

        $repository = $this->createMock(MissingTranslationLogRepository::class);
        $repository->expects(self::once())->method('persistBuffer')->with($buffer);

        $handler = new PersistMissingTranslationBufferMessageHandler($repository);
        ($handler)(new MissingTranslationBufferMessage($buffer));
    }
}
