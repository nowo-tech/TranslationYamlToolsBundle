<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use Nowo\TranslationYamlToolsBundle\MachineTranslation\LibreTranslateMachineTranslator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(LibreTranslateMachineTranslator::class)]
final class LibreTranslateMachineTranslatorTest extends TestCase
{
    public function testTranslateSuccess(): void
    {
        $body = json_encode(['translatedText' => 'Hola'], \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '');
        self::assertSame('Hola', $translator->translate('Hello', 'en', 'es'));
    }

    public function testTranslateEmptyStringReturnsEmpty(): void
    {
        $client = new MockHttpClient();
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '');
        self::assertSame('', $translator->translate('', 'en', 'es'));
    }

    public function testThrowsOnHttpError(): void
    {
        $client = new MockHttpClient([
            new MockResponse('busy', ['http_code' => 503]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LibreTranslate HTTP 503');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenTranslatedTextMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing translatedText');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsOnErrorField(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"error":"slowdown"}', ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('LibreTranslate error: slowdown');
        $translator->translate('x', 'en', 'de');
    }

    public function testSendsLanguageCodesAndOptionalApiKey(): void
    {
        $body = json_encode(['translatedText' => 'ok'], \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(function ($method, $url, $options) use ($body) {
            self::assertSame('POST', $method);
            self::assertSame('https://lt.example/translate', $url);
            $raw = $options['body'] ?? '';
            if (\is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('en', $payload['source'] ?? null);
            self::assertSame('pt', $payload['target'] ?? null);
            self::assertSame('secret', $payload['api_key'] ?? null);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example/', 'secret');
        $translator->translate('x', 'en_GB', 'pt_BR');
    }

    public function testOmitsApiKeyWhenEmpty(): void
    {
        $body = json_encode(['translatedText' => 'ok'], \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(function ($method, $url, $options) use ($body) {
            $raw = $options['body'] ?? '';
            if (\is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('api_key', $payload);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsOnInvalidJsonResponse(): void
    {
        $client = new MockHttpClient([
            new MockResponse('not-json', ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '');
        $this->expectException(\JsonException::class);
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenLocaleEmpty(): void
    {
        $body = json_encode(['translatedText' => 'x'], \JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '');
        $this->expectException(\InvalidArgumentException::class);
        $translator->translate('hello', '', 'es');
    }
}
