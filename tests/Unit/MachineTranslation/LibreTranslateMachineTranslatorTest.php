<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use InvalidArgumentException;
use JsonException;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\LibreTranslateMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function is_resource;

use const JSON_THROW_ON_ERROR;

#[CoversClass(LibreTranslateMachineTranslator::class)]
final class LibreTranslateMachineTranslatorTest extends TestCase
{
    private function emptyMapper(): MachineTranslationLocaleMapper
    {
        return new MachineTranslationLocaleMapper([]);
    }

    public function testTranslateSuccess(): void
    {
        $body   = json_encode(['translatedText' => 'Hola'], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $this->emptyMapper());
        self::assertSame('Hola', $translator->translate('Hello', 'en', 'es'));
    }

    public function testTranslateEmptyStringReturnsEmpty(): void
    {
        $client     = new MockHttpClient();
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $this->emptyMapper());
        self::assertSame('', $translator->translate('', 'en', 'es'));
    }

    public function testThrowsOnHttpError(): void
    {
        $client = new MockHttpClient([
            new MockResponse('busy', ['http_code' => 503]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LibreTranslate HTTP 503');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenTranslatedTextMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing translatedText');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsOnErrorField(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"error":"slowdown"}', ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('LibreTranslate error: slowdown');
        $translator->translate('x', 'en', 'de');
    }

    public function testSendsLanguageCodesAndOptionalApiKey(): void
    {
        $body   = json_encode(['translatedText' => 'ok'], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(static function ($method, $url, array $options) use ($body): \Symfony\Component\HttpClient\Response\MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://lt.example/translate', $url);
            $raw = $options['body'] ?? '';
            if (is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('en', $payload['source'] ?? null);
            self::assertSame('pt', $payload['target'] ?? null);
            self::assertSame('secret', $payload['api_key'] ?? null);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example/', 'secret', $this->emptyMapper());
        $translator->translate('x', 'en_GB', 'pt_BR');
    }

    public function testOmitsApiKeyWhenEmpty(): void
    {
        $body   = json_encode(['translatedText' => 'ok'], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(static function ($method, $url, array $options) use ($body): \Symfony\Component\HttpClient\Response\MockResponse {
            $raw = $options['body'] ?? '';
            if (is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('api_key', $payload);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $this->emptyMapper());
        $translator->translate('x', 'en', 'de');
    }

    public function testConfiguredLocaleMapSendsExactCodeToLibre(): void
    {
        $body   = json_encode(['translatedText' => 'ok'], JSON_THROW_ON_ERROR);
        $mapper = new MachineTranslationLocaleMapper(['pt_br' => 'pt-br']);
        $client = new MockHttpClient(static function ($method, $url, array $options) use ($body): \Symfony\Component\HttpClient\Response\MockResponse {
            $raw = $options['body'] ?? '';
            if (is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('en', $payload['source'] ?? null);
            self::assertSame('pt-br', $payload['target'] ?? null);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $mapper);
        $translator->translate('x', 'en', 'PT_BR');
    }

    public function testThrowsOnInvalidJsonResponse(): void
    {
        $client = new MockHttpClient([
            new MockResponse('not-json', ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $this->emptyMapper());
        $this->expectException(JsonException::class);
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenLocaleEmpty(): void
    {
        $body   = json_encode(['translatedText' => 'x'], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $translator = new LibreTranslateMachineTranslator($client, 'https://lt.example', '', $this->emptyMapper());
        $this->expectException(InvalidArgumentException::class);
        $translator->translate('hello', '', 'es');
    }
}
