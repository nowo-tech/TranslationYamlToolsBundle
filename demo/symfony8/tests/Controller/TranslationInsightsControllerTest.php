<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TranslationInsightsControllerTest extends WebTestCase
{
    public function testHomePageReturns200AndShowsLocaleInfo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Translation YAML Tools');
        self::assertSelectorTextContains('body', 'translator.default_locale');
        self::assertSelectorTextContains('body', 'kernel.enabled_locales');
    }
}
