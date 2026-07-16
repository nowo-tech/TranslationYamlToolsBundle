<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    public function testLoginPageIsPublic(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Demo login');
        self::assertSelectorTextContains('body', 'admin');
    }

    public function testMissingLogUiRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_demo/translation-yaml-tools/missing-log/');

        self::assertResponseRedirects('/login');
    }
}
