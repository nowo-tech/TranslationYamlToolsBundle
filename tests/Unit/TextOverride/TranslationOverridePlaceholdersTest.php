<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use Nowo\TranslationYamlToolsBundle\TextOverride\TranslationOverridePlaceholders;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TranslationOverridePlaceholders::class)]
final class TranslationOverridePlaceholdersTest extends TestCase
{
    public function testExtractsSymfonyAndIcuParametersButNotPluralSelectors(): void
    {
        self::assertSame([], TranslationOverridePlaceholders::of('Plain text, 100% sure'));
        self::assertSame(['%count%', '%name%'], TranslationOverridePlaceholders::of('Hi %name%, %count% new (%name%)'));
        self::assertSame(['{count}', '{name}'], TranslationOverridePlaceholders::of('{ name } has {count, plural, one {# item} other {# items}}'));
        self::assertSame(['%count%'], TranslationOverridePlaceholders::of('{0}None|{1}One|]1,Inf[%count% items'));
    }

    public function testMatchRequiresTheSamePlaceholders(): void
    {
        self::assertTrue(TranslationOverridePlaceholders::match('Hello %name%', 'Hola %name%, <strong>bienvenido</strong>'));
        self::assertTrue(TranslationOverridePlaceholders::match('Open Mon–Fri', 'Open Mon–Thu'));
        self::assertFalse(TranslationOverridePlaceholders::match('Read more', '<a href="%url%">Read more</a>'), 'An added parameter is refused.');
        self::assertFalse(TranslationOverridePlaceholders::match('Hello %name%', 'Hello'), 'A dropped parameter is refused.');
        self::assertFalse(TranslationOverridePlaceholders::match('Hello {name}', 'Hello %name%'));
    }
}
