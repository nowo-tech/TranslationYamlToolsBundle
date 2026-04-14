<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MissingTranslationLog;

use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferEvent;
use Nowo\TranslationYamlToolsBundle\MissingTranslationLog\MissingTranslationBufferMessage;
use PHPUnit\Framework\TestCase;

final class MissingTranslationBufferDtosTest extends TestCase
{
    public function testMessageExposesBuffer(): void
    {
        $b = ['x' => ['hits' => 1, 'messageId' => 'i', 'domain' => 'm', 'locale' => 'en', 'callSite' => null]];
        $m = new MissingTranslationBufferMessage($b);
        self::assertSame($b, $m->buffer);
    }

    public function testEventExposesBuffer(): void
    {
        $b = ['x' => ['hits' => 1, 'messageId' => 'i', 'domain' => 'm', 'locale' => 'en', 'callSite' => null]];
        $e = new MissingTranslationBufferEvent($b);
        self::assertSame($b, $e->buffer);
    }
}
