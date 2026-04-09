<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\MachineTranslation;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_array;
use function is_string;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * DeepL API v2 translate endpoint (JSON).
 *
 * @see https://developers.deepl.com/docs/api-reference/translate
 */
final class DeeplMachineTranslator implements MachineTranslatorInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $authKey,
        private readonly string $endpointUrl,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function translate(string $text, string $sourceLocale, string $targetLocale): string
    {
        if ($this->authKey === '') {
            throw new RuntimeException('DeepL API key is empty. Set the DEEPL_AUTH_KEY environment variable (see docs/CONFIGURATION.md).');
        }

        if ($text === '') {
            return '';
        }

        $response = $this->httpClient->request('POST', $this->endpointUrl, [
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $this->authKey,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'text'        => [$text],
                'source_lang' => $this->toDeepLLang($sourceLocale),
                'target_lang' => $this->toDeepLLang($targetLocale),
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf('DeepL HTTP %d: %s', $status, $response->getContent(false)));
        }

        /** @var array<string, mixed> $data */
        $data         = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $translations = $data['translations'] ?? null;
        if (!is_array($translations) || $translations === []) {
            throw new RuntimeException('DeepL response missing translations.');
        }

        $first = $translations[0] ?? null;
        if (!is_array($first) || !isset($first['text']) || !is_string($first['text'])) {
            throw new RuntimeException('DeepL response malformed.');
        }

        return $first['text'];
    }

    /**
     * Maps Symfony-style locales (en, en_US, pt_BR) to DeepL language codes (EN, EN-US, PT-BR).
     */
    private function toDeepLLang(string $locale): string
    {
        $locale = str_replace('_', '-', trim($locale));
        if ($locale === '') {
            throw new InvalidArgumentException('Locale must not be empty.');
        }

        $parts    = explode('-', $locale, 2);
        $language = strtoupper($parts[0]);
        if (!isset($parts[1])) {
            return $language;
        }

        $region = strtoupper(str_replace('-', '', $parts[1]));

        return match (true) {
            $language === 'EN' && $region === 'US' => 'EN-US',
            $language === 'EN' && $region === 'GB' => 'EN-GB',
            $language === 'PT' && $region === 'BR' => 'PT-BR',
            default                                => $language . '-' . $region,
        };
    }
}
