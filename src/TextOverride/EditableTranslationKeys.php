<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride;

use Symfony\Component\Translation\MessageCatalogueInterface;

use function array_key_exists;
use function count;

/**
 * Which catalogue messages operators may override ({@code overrides.editable}: domain => key prefixes).
 *
 * UI references: keys of the {@code messages} domain keep their plain form; other domains are
 * addressed as {@code Domain:key}. A reference whose prefix before ":" is not a configured
 * non-messages domain is read as a {@code messages} key.
 */
final class EditableTranslationKeys
{
    public const DEFAULT_DOMAIN = 'messages';

    /**
     * @param array<string, list<string>> $editable domain => key prefixes ('' = whole domain)
     */
    public function __construct(
        private readonly array $editable,
    ) {
    }

    /**
     * @return array<string, list<string>>
     */
    public function domains(): array
    {
        return $this->editable;
    }

    public function isEditable(string $domain, string $key): bool
    {
        if ($key === '') {
            return false;
        }

        foreach ($this->editable[$domain] ?? [] as $prefix) {
            if ($prefix === '' || str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string} [domain, key]
     */
    public function parseRef(string $ref): array
    {
        if (str_contains($ref, ':')) {
            [$domain, $key] = explode(':', $ref, 2);
            if ($domain !== self::DEFAULT_DOMAIN && array_key_exists($domain, $this->editable)) {
                return [$domain, $key];
            }
        }

        return [self::DEFAULT_DOMAIN, $ref];
    }

    public function ref(string $domain, string $key): string
    {
        return $domain === self::DEFAULT_DOMAIN ? $key : $domain . ':' . $key;
    }

    /**
     * {@code site.footer.tagline} → {@code site.footer}; {@code error.404} → {@code error}.
     * Non-messages domains are prefixed ({@code Domain:loader}).
     */
    public function groupOf(string $domain, string $key): string
    {
        $parts = explode('.', $key);
        $group = count($parts) > 2 ? $parts[0] . '.' . $parts[1] : $parts[0];

        return $this->ref($domain, $group);
    }

    /**
     * Every editable message of a catalogue, keyed by reference and sorted.
     *
     * @return array<string, string> ref => text
     */
    public function editableMessages(MessageCatalogueInterface $catalogue): array
    {
        $messages = [];
        foreach (array_keys($this->editable) as $domain) {
            foreach ($catalogue->all($domain) as $key => $text) {
                $key = (string) $key;
                if ($this->isEditable($domain, $key)) {
                    $messages[$this->ref($domain, $key)] = (string) $text;
                }
            }
        }
        ksort($messages);

        return $messages;
    }
}
