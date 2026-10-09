<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\TextOverride;

use Nowo\TranslationYamlToolsBundle\TextOverride\EditableTranslationKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\MessageCatalogue;

#[CoversClass(EditableTranslationKeys::class)]
final class EditableTranslationKeysTest extends TestCase
{
    private EditableTranslationKeys $keys;

    protected function setUp(): void
    {
        $this->keys = new EditableTranslationKeys([
            'messages'   => ['site.', 'legal.'],
            'ShopBundle' => ['loader.'],
            'everything' => [''],
        ]);
    }

    public function testEditableByDomainAndPrefix(): void
    {
        self::assertTrue($this->keys->isEditable('messages', 'site.footer.tagline'));
        self::assertTrue($this->keys->isEditable('messages', 'legal.notice'));
        self::assertFalse($this->keys->isEditable('messages', 'flash.saved'));
        self::assertTrue($this->keys->isEditable('ShopBundle', 'loader.loading'));
        self::assertFalse($this->keys->isEditable('ShopBundle', 'site.x'));
        self::assertFalse($this->keys->isEditable('security', 'site.x'));
        self::assertTrue($this->keys->isEditable('everything', 'any.key'));
        self::assertFalse($this->keys->isEditable('everything', ''));
        self::assertSame(['site.', 'legal.'], $this->keys->domains()['messages']);
    }

    public function testRefsRoundTrip(): void
    {
        self::assertSame(['messages', 'site.a'], $this->keys->parseRef('site.a'));
        self::assertSame(['ShopBundle', 'loader.loading'], $this->keys->parseRef('ShopBundle:loader.loading'));
        self::assertSame(['messages', 'security:Invalid credentials.'], $this->keys->parseRef('security:Invalid credentials.'), 'Unknown domain: read as a messages key (never editable).');
        self::assertSame(['messages', 'messages:site.a'], $this->keys->parseRef('messages:site.a'));
        self::assertSame('site.a', $this->keys->ref('messages', 'site.a'));
        self::assertSame('ShopBundle:loader.loading', $this->keys->ref('ShopBundle', 'loader.loading'));
    }

    public function testGroups(): void
    {
        self::assertSame('site.footer', $this->keys->groupOf('messages', 'site.footer.tagline'));
        self::assertSame('error', $this->keys->groupOf('messages', 'error.404'));
        self::assertSame('ShopBundle:loader', $this->keys->groupOf('ShopBundle', 'loader.loading'));
    }

    public function testEditableMessagesOfCatalogue(): void
    {
        $catalogue = new MessageCatalogue('en', [
            'messages'   => ['site.b' => 'B', 'site.a' => 'A', 'admin.x' => 'X'],
            'ShopBundle' => ['loader.loading' => 'Loading', 'admin.title' => 'Shop'],
            'security'   => ['site.c' => 'C'],
        ]);

        self::assertSame(
            ['ShopBundle:loader.loading' => 'Loading', 'site.a' => 'A', 'site.b' => 'B'],
            $this->keys->editableMessages($catalogue),
        );
    }
}
