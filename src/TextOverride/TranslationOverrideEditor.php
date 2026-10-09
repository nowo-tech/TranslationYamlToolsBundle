<?php

declare(strict_types=1);

namespace Nowo\TranslationYamlToolsBundle\TextOverride;

use Symfony\Component\Translation\TranslatorBagInterface;

use function array_key_exists;
use function is_scalar;

/**
 * Per-locale editing of one message (used by the Web UI; also the "form bridge" for host forms).
 *
 * Host forms can bind an unmapped per-locale field to a message:
 *
 *     $form->get('title')->setData($editor->currentTexts('messages', 'site.page.title'));
 *     // on submit:
 *     $editor->save('messages', 'site.page.title', $form->get('title')->getData(), flush: false);
 *     $entityManager->flush(); $overrides->invalidate(); // or $editor->flush()
 *
 * Text equal to the shipped catalogue text (after trimming / sanitizing) or blank removes the
 * override, so untouched locales never create rows.
 */
final class TranslationOverrideEditor
{
    public function __construct(
        private readonly TranslationOverrides $overrides,
        private readonly TranslatorBagInterface $translator,
        private readonly TranslationOverrideLocales $locales,
    ) {
    }

    /**
     * @return list<string>
     */
    public function locales(): array
    {
        return $this->locales->all();
    }

    public function defaultLocale(): string
    {
        return $this->locales->default();
    }

    /**
     * Catalogue (shipped) text per locale; '' when the key is unknown in that locale and its fallbacks.
     *
     * @return array<string, string> locale => text
     */
    public function shippedTexts(string $domain, string $key): array
    {
        $texts = [];
        foreach ($this->locales->all() as $locale) {
            $catalogue      = $this->translator->getCatalogue($locale);
            $texts[$locale] = $catalogue->has($key, $domain) ? $catalogue->get($key, $domain) : '';
        }

        return $texts;
    }

    /**
     * Override text, else shipped text, per locale.
     *
     * @return array<string, string> locale => text
     */
    public function currentTexts(string $domain, string $key): array
    {
        $texts = $this->shippedTexts($domain, $key);
        foreach ($texts as $locale => $shipped) {
            $texts[$locale] = $this->overrides->get($locale, $domain, $key) ?? $shipped;
        }

        return $texts;
    }

    /**
     * @return list<string> locales with an override
     */
    public function overriddenLocales(string $domain, string $key): array
    {
        return array_values(array_filter(
            $this->locales->all(),
            fn (string $locale): bool => $this->overrides->get($locale, $domain, $key) !== null,
        ));
    }

    /**
     * Stores submitted texts (locale => text); locales missing from $texts are left untouched.
     *
     * @param array<array-key, mixed> $texts
     */
    public function save(string $domain, string $key, array $texts, bool $flush = true, ?string $updatedBy = null): void
    {
        $shipped = $this->shippedTexts($domain, $key);
        foreach ($this->locales->all() as $locale) {
            if (!array_key_exists($locale, $texts)) {
                continue;
            }
            $value = $texts[$locale];
            $text  = $this->overrides->clean(is_scalar($value) ? (string) $value : null);
            // @igor-ignore - Persists a DB write; TranslationOverrides keeps no per-request state beyond its reset() memo.
            $this->overrides->set($locale, $domain, $key, $text === trim($shipped[$locale]) ? null : $text, false, $updatedBy);
        }

        if ($flush) {
            $this->overrides->flush();
        }
    }

    /**
     * Removes the override of every locale.
     */
    public function reset(string $domain, string $key, bool $flush = true): void
    {
        foreach ($this->locales->all() as $locale) {
            // @igor-ignore - Persists a DB write; TranslationOverrides keeps no per-request state beyond its reset() memo.
            $this->overrides->set($locale, $domain, $key, null, false);
        }

        if ($flush) {
            $this->overrides->flush();
        }
    }

    public function flush(): void
    {
        $this->overrides->flush();
    }
}
