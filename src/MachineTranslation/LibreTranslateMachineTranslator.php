<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MachineTranslation;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_string;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * LibreTranslate HTTP API (public or self-hosted). No API key is required on many instances; paid or private servers may require one.
 *
 * @see https://github.com/LibreTranslate/LibreTranslate/blob/main/API.md
 */
final class LibreTranslateMachineTranslator implements MachineTranslatorInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly MachineTranslationLocaleMapper $localeMapper,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): string
    {
        if ($text === '') {
            return '';
        }

        $url     = rtrim($this->baseUrl, '/') . '/translate';
        $payload = [
            'q'      => $text,
            'source' => $this->resolveLanguageCode($sourceLocale),
            'target' => $this->resolveLanguageCode($targetLocale),
            'format' => 'text',
        ];
        if ($this->apiKey !== '') {
            $payload['api_key'] = $this->apiKey;
        }

        $response = $this->httpClient->request('POST', $url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json'    => $payload,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('LibreTranslate HTTP %d: %s', $status, $response->getContent(false)));
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
            throw new RuntimeException('LibreTranslate error: ' . $data['error']);
        }

        $translated = $data['translatedText'] ?? null;
        if (!is_string($translated)) {
            throw new RuntimeException('LibreTranslate response missing translatedText.');
        }

        return $translated;
    }

    private function resolveLanguageCode(string $locale): string
    {
        $mapped = $this->localeMapper->map($locale);
        if ($mapped !== null) {
            return $mapped;
        }

        return $this->toLanguageCode($locale);
    }

    /**
     * Maps Symfony-style locales to LibreTranslate language codes (typically ISO 639-1).
     */
    private function toLanguageCode(string $locale): string
    {
        $locale = str_replace('_', '-', trim($locale));
        if ($locale === '') {
            throw new InvalidArgumentException('Locale must not be empty.');
        }

        $parts = explode('-', $locale, 2);

        return strtolower($parts[0]);
    }
}
