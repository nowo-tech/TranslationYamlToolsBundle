<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\Tests\Unit\MachineTranslation;

use JsonException;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\GoogleTranslateMachineTranslator;
use Nowo\TranslationYamlToolsBundle\MachineTranslation\MachineTranslationLocaleMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function is_resource;

use const JSON_THROW_ON_ERROR;

#[CoversClass(GoogleTranslateMachineTranslator::class)]
final class GoogleTranslateMachineTranslatorTest extends TestCase
{
    private function emptyMapper(): MachineTranslationLocaleMapper
    {
        return new MachineTranslationLocaleMapper([]);
    }

    public function testTranslateSuccess(): void
    {
        $body = json_encode([
            'data' => [
                'translations' => [
                    ['translatedText' => 'Hola'],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient([
            new MockResponse($body, ['http_code' => 200]),
        ]);
        $translator = new GoogleTranslateMachineTranslator($client, 'api-key', $this->emptyMapper());
        self::assertSame('Hola', $translator->translate('Hello', 'en', 'es'));
    }

    public function testThrowsWhenApiKeyEmpty(): void
    {
        $client     = new MockHttpClient();
        $translator = new GoogleTranslateMachineTranslator($client, '', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GOOGLE_TRANSLATE_API_KEY');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsOnHttpError(): void
    {
        $client = new MockHttpClient([
            new MockResponse('bad', ['http_code' => 503]),
        ]);
        $translator = new GoogleTranslateMachineTranslator($client, 'k', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Google Translate HTTP 503');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenTranslationsMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"data":{}}', ['http_code' => 200]),
        ]);
        $translator = new GoogleTranslateMachineTranslator($client, 'k', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing translations');
        $translator->translate('x', 'en', 'de');
    }

    public function testThrowsWhenResponseMalformed(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{"data":{"translations":[{}]}}', ['http_code' => 200]),
        ]);
        $translator = new GoogleTranslateMachineTranslator($client, 'k', $this->emptyMapper());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed');
        $translator->translate('x', 'en', 'de');
    }

    public function testNormalizeLocaleUsesRegion(): void
    {
        $body = json_encode([
            'data' => ['translations' => [['translatedText' => 'ok']]],
        ], JSON_THROW_ON_ERROR);
        $client = new MockHttpClient(static function ($method, $url, array $options) use ($body): MockResponse {
            $raw = $options['body'] ?? '';
            if (is_resource($raw)) {
                $raw = stream_get_contents($raw);
            }
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('en-GB', $payload['source'] ?? null);
            self::assertSame('pt-BR', $payload['target'] ?? null);

            return new MockResponse($body, ['http_code' => 200]);
        });
        $translator = new GoogleTranslateMachineTranslator($client, 'k', $this->emptyMapper());
        $translator->translate('x', 'en_GB', 'pt_BR');
    }

    public function testConfiguredLocaleMapOverridesNormalization(): void
    {
        $body = json_encode([
            'data' => ['translations' => [['translatedText' => 'ok']]],
        ], JSON_THROW_ON_ERROR);
        $mapper = new MachineTranslationLocaleMapper([
            'pt_br' => 'pt-br',
        ]);
        $client = new MockHttpClient(static function ($method, $url, array $options) use ($body): MockResponse {
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
        $translator = new GoogleTranslateMachineTranslator($client, 'k', $mapper);
        $translator->translate('x', 'en', 'pt_BR');
    }

    public function testThrowsOnInvalidJsonResponse(): void
    {
        $client = new MockHttpClient([
            new MockResponse('not-json', ['http_code' => 200]),
        ]);
        $translator = new GoogleTranslateMachineTranslator($client, 'k', $this->emptyMapper());
        $this->expectException(JsonException::class);
        $translator->translate('x', 'en', 'de');
    }
}
