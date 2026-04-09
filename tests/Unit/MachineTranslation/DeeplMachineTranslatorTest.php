<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use InvalidArgumentException;
use JsonException;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\DeeplMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_resource;

use const JSON_THROW_ON_ERROR;

#[CoversClass(DeeplMachineTranslator::class)]
final class DeeplMachineTranslatorTest extends TestCase
{
    private function emptyMapper(): MachineTranslationLocaleMapper
    {
        return new MachineTranslationLocaleMapper([]);
    }

    public function testTranslateSuccess(): void
    {
        $body = json_encode([
            'translations' => [
                ['detected_source_language' => 'EN', 'text' => 'Hola'],
            ],
        ], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $translator = new DeeplMachineTranslator($client, 'auth', 'https://api.example.com/v2/translate', $this->emptyMapper());
        self::assertSame('Hola', $translator->translate('Hello', 'en', 'es'));
    }

    public function testEmptyTextSkipsRequest(): void
    {
        $client = $this->createMock(HttpClientInterface::class);
        $client->expects(self::never())->method('request');
        $translator = new DeeplMachineTranslator($client, 'auth', 'https://api.example.com/v2/translate', $this->emptyMapper());
        self::assertSame('', $translator->translate('', 'en', 'es'));
    }

    public function testThrowsWhenAuthKeyEmpty(): void
    {
        $client     = new MockHttpClient();
        $translator = new DeeplMachineTranslator($client, '', 'https://api.example.com/v2/translate', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEEPL_AUTH_KEY');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsOnHttpError(): void
    {
        $client = new MockHttpClient([
            new MockResponse('quota', ['http_code' => 456]),
        ]);
        $translator = new DeeplMachineTranslator($client, 'k', 'https://api.example.com/v2/translate', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DeepL HTTP 456');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenTranslationsMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 200]),
        ]);
        $translator = new DeeplMachineTranslator($client, 'k', 'https://api.example.com/v2/translate', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing translations');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenResponseMalformed(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"translations":[{}]}', ['http_code' => 200]),
        ]);
        $translator = new DeeplMachineTranslator($client, 'k', 'https://api.example.com/v2/translate', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenLocaleEmpty(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"translations":[{"text":"x"}]}', ['http_code' => 200]),
        ]);
        $translator = new DeeplMachineTranslator($client, 'k', 'https://api.example.com/v2/translate', $this->emptyMapper());
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Locale must not be empty');
        $translator->translate('hi', '   ', 'en');
    }

    public function testMapsRegionalLocalesInRequest(): void
    {
        $body   = json_encode(['translations' => [['text' => 'ok']]], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(static function ($method, $url, array $options) use ($body): MockResponse {
            $raw = $options['body'] ?? '';
            if (is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('EN-US', $payload['source_lang'] ?? null);
            self::assertSame('PT-BR', $payload['target_lang'] ?? null);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $translator = new DeeplMachineTranslator($client, 'k', 'https://api.example.com/v2/translate', $this->emptyMapper());
        $translator->translate('x', 'en_US', 'pt_BR');
    }

    public function testConfiguredLocaleMapOverridesDeepLNormalization(): void
    {
        $body   = json_encode(['translations' => [['text' => 'ok']]], JSON_THROW_ON_ERROR);
        $mapper = new MachineTranslationLocaleMapper(['pt_br' => 'PT']);
        $client = new MockHttpClient(static function ($method, $url, array $options) use ($body): MockResponse {
            $raw = $options['body'] ?? '';
            if (is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('EN', $payload['source_lang'] ?? null);
            self::assertSame('PT', $payload['target_lang'] ?? null);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $translator = new DeeplMachineTranslator($client, 'k', 'https://api.example.com/v2/translate', $mapper);
        $translator->translate('x', 'en', 'pt-br');
    }

    public function testThrowsOnInvalidJsonResponse(): void
    {
        $client = new MockHttpClient([
            new MockResponse('not-json', ['http_code' => 200]),
        ]);
        $translator = new DeeplMachineTranslator($client, 'k', 'https://api.example.com/v2/translate', $this->emptyMapper());
        $this->expectException(JsonException::class);
        $translator->translate('x', 'en', 'de');
    }
}
