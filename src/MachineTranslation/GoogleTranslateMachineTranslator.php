<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MachineTranslation;

use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_array;
use function is_string;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Google Cloud Translation API v2 (REST) implementation.
 *
 * @see https://cloud.google.com/translate/docs/reference/rest/v2/translate
 */
final class GoogleTranslateMachineTranslator implements MachineTranslatorInterface
{
    private const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly MachineTranslationLocaleMapper $localeMapper,
        private readonly float $httpTimeout = 30.0,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): string
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('Google Translation API key is empty. Set the GOOGLE_TRANSLATE_API_KEY environment variable (see docs/CONFIGURATION.md).');
        }

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'timeout' => $this->httpTimeout,
            'query'   => ['key' => $this->apiKey],
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => [
                'q'      => [$text],
                'source' => $this->resolveLocaleForApi($sourceLocale),
                'target' => $this->resolveLocaleForApi($targetLocale),
                'format' => 'text',
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('Google Translate HTTP %d: %s', $status, $response->getContent(false)));
        }

        /** @var array<string, mixed> $data */
        $data         = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $translations = $data['data']['translations'] ?? null;
        if (!is_array($translations) || $translations === []) {
            throw new RuntimeException('Google Translate response missing translations.');
        }

        $first = $translations[0] ?? null;
        if (!is_array($first) || !isset($first['translatedText']) || !is_string($first['translatedText'])) {
            throw new RuntimeException('Google Translate response malformed.');
        }

        return $first['translatedText'];
    }

    private function resolveLocaleForApi(string $locale): string
    {
        $mapped = $this->localeMapper->map($locale);
        if ($mapped !== null) {
            return $mapped;
        }

        return $this->normalizeLocale($locale);
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = str_replace('_', '-', $locale);
        $parts  = explode('-', $locale, 2);

        return strtolower($parts[0]) . (isset($parts[1]) ? '-' . strtoupper($parts[1]) : '');
    }
}
