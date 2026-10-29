<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use Closure;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\DeeplMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\GoogleTranslateMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\LibreTranslateMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * REQ-RUNTIME-001: every machine-translation backend sends an idle and a total deadline taken from
 * `machine_translation_http_timeout`, and a timed-out request fails with a transport error instead of hanging.
 */
#[CoversClass(GoogleTranslateMachineTranslator::class)]
#[CoversClass(DeeplMachineTranslator::class)]
#[CoversClass(LibreTranslateMachineTranslator::class)]
final class MachineTranslatorTimeoutTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(MockHttpClient, float): MachineTranslatorInterface}>
     */
    public static function translators(): iterable
    {
        $mapper = new MachineTranslationLocaleMapper([]);

        yield 'google' => [static fn (MockHttpClient $client, float $timeout): MachineTranslatorInterface => new GoogleTranslateMachineTranslator($client, 'REDACTED-TEST', $mapper, $timeout)];
        yield 'deepl' => [static fn (MockHttpClient $client, float $timeout): MachineTranslatorInterface => new DeeplMachineTranslator($client, 'REDACTED-TEST', 'https://api.example.com/v2/translate', $mapper, $timeout)];
        yield 'libretranslate' => [static fn (MockHttpClient $client, float $timeout): MachineTranslatorInterface => new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $mapper, null, $timeout)];
    }

    /**
     * @param Closure(MockHttpClient, float): MachineTranslatorInterface $factory
     */
    #[DataProvider('translators')]
    public function testTimedOutRequestFailsWithConfiguredDeadlines(Closure $factory): void
    {
        $sentOptions = [];
        $client      = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sentOptions): ResponseInterface {
            $sentOptions = $options;

            return new MockResponse('', ['error' => 'Idle timeout reached for "' . $url . '".']);
        });

        $translator = $factory($client, 2.5);

        try {
            $translator->translate('Hello', 'en', 'es');
            self::fail('A timed-out request must not return a translation.');
        } catch (TransportExceptionInterface $e) {
            self::assertStringContainsString('timeout', $e->getMessage());
        }

        self::assertSame(2.5, $sentOptions['timeout'] ?? null);
        self::assertSame(2.5, $sentOptions['max_duration'] ?? null);
    }
}
